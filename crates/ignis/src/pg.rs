//! E14 (ADR-0015): runtime-owned PostgreSQL pool with per-fiber leases.
//!
//! Pools and connections live here (tokio side); PHP holds integer lease ids and
//! parks one fiber per op (`Op::Custom`). Parameters and rows cross as JSON;
//! bindings follow the prepared statement's parameter types, unsupported
//! types are errors, never silent strings.

use std::collections::HashMap;
use std::future::Future;
use std::pin::Pin;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};

use base64::Engine as _;
use serde_json::{Value, json};
use tokio::sync::{Mutex as AsyncMutex, OwnedSemaphorePermit, Semaphore};
use tokio_postgres::types::{ToSql, Type};
use tokio_postgres::{Client, NoTls, Row, Statement};

use crate::reactor::Outcome;

/// A connection plus its prepared-statement cache (kept across leases because the
/// session reset below deliberately leaves prepared statements alone).
struct Connection {
    client: Client,
    stmts: HashMap<String, Statement>,
}

struct Pool {
    dsn: String,
    idle: Mutex<Vec<Connection>>,
    sem: Arc<Semaphore>,
    created: AtomicU64,
    /// M4-12: the capacity the first opener asked for, so a later opener with a different `max`
    /// can be warned rather than silently getting the first one's.
    max: usize,
    /// M4-2/B2: the state that lets a down database cost a fiber nothing instead of the ceiling.
    breaker: Mutex<Breaker>,
}

/// M4-2/B2, pain-map "PHP-FPM 2": consecutive acquire failures open the pool, an open pool refuses
/// without touching the network, and one probe after the cooldown decides whether to close it again.
#[derive(Default)]
struct Breaker {
    consecutive_failures: u32,
    open_until: Option<Instant>,
    probing: bool,
}

impl Breaker {
    /// `Ok` when the caller may try the database: the breaker is closed, or the cooldown has passed
    /// and this caller is the one probe allowed through.
    fn admit(&mut self) -> Result<(), String> {
        let Some(until) = self.open_until else { return Ok(()) };
        if self.probing {
            return Err(format!(
                "circuit breaker half-open after {} consecutive failures; one probe is already in flight",
                self.consecutive_failures
            ));
        }
        let left = until.saturating_duration_since(Instant::now());
        if !left.is_zero() {
            return Err(format!(
                "circuit breaker open after {} consecutive failures; not connecting for another {} ms (IGNIS_PG_BREAKER_COOLDOWN_MS)",
                self.consecutive_failures,
                left.as_millis()
            ));
        }
        self.probing = true;
        Ok(())
    }

    fn record(&mut self, acquired: bool) {
        self.probing = false;
        if acquired {
            self.consecutive_failures = 0;
            self.open_until = None;
            return;
        }
        self.consecutive_failures += 1;
        let threshold = tuning().breaker_failures;
        if threshold > 0 && self.consecutive_failures >= threshold {
            self.open_until = Some(Instant::now() + tuning().breaker_cooldown);
        }
    }
}

struct Lease {
    pool: Arc<Pool>,
    connection: Connection,
    _permit: OwnedSemaphorePermit,
    /// M4-1: when the lease was handed out, so a held connection is visible (oldest age in `stats`)
    /// and logged (`release` warns past `IGNIS_PG_LEASE_WARN_MS`).
    since: Instant,
}

/// `DISCARD ALL` minus `DEALLOCATE ALL`/`DISCARD PLANS` (its documented expansion), so the
/// statement cache survives; `ROLLBACK` first ends any open transaction (a WARNING otherwise).
/// One round trip. `DISCARD ALL` itself cannot run in the implicit transaction block a
/// multi-statement string creates, which is why it is expanded here.
const RESET_SQL: &str = "ROLLBACK; CLOSE ALL; SET SESSION AUTHORIZATION DEFAULT; RESET ALL; UNLISTEN *; SELECT pg_advisory_unlock_all(); DISCARD TEMP; DISCARD SEQUENCES";
const STMT_CACHE_MAX: usize = 256;

type Fut = Pin<Box<dyn Future<Output = Outcome> + Send>>;

static POOLS: OnceLock<Mutex<HashMap<u64, Arc<Pool>>>> = OnceLock::new();
/// A lease slot: `None` once released, so a late query fails instead of touching a reused lease.
type LeaseSlot = Arc<AsyncMutex<Option<Lease>>>;

static LEASES: OnceLock<Mutex<HashMap<u64, LeaseSlot>>> = OnceLock::new();
/// M4-11: lease id → the reactor (thread) that acquired it, kept outside the lease's async mutex
/// so a dying thread's leases can be found without waiting on a query in flight (V-42).
static OWNERS: OnceLock<Mutex<HashMap<u64, usize>>> = OnceLock::new();
/// M4-12: DSN → pool id, so every worker thread's `ignis_pg_open` of the same DSN shares one pool
/// instead of each minting its own (V-43: `--threads 3` gave three pools of `max` each).
static BY_DSN: OnceLock<Mutex<HashMap<String, u64>>> = OnceLock::new();
static NEXT: AtomicU64 = AtomicU64::new(1);

fn pools() -> &'static Mutex<HashMap<u64, Arc<Pool>>> {
    POOLS.get_or_init(|| Mutex::new(HashMap::new()))
}
fn leases() -> &'static Mutex<HashMap<u64, Arc<AsyncMutex<Option<Lease>>>>> {
    LEASES.get_or_init(|| Mutex::new(HashMap::new()))
}
fn owners() -> &'static Mutex<HashMap<u64, usize>> {
    OWNERS.get_or_init(|| Mutex::new(HashMap::new()))
}
fn by_dsn() -> &'static Mutex<HashMap<String, u64>> {
    BY_DSN.get_or_init(|| Mutex::new(HashMap::new()))
}
fn failed(message: String) -> Outcome {
    Outcome::Failed(format!("pg: {message}"))
}

/// M4-12: the pool already open for this DSN, if any. Every worker thread runs the same script and
/// calls `open`; before this, each call minted its own pool (V-43: `--threads 3` gave three pools
/// of `max` each).
fn pool_already_open_for_dsn(dsn: &str, max: usize) -> Option<u64> {
    let &id = by_dsn().lock().unwrap().get(dsn)?;
    if let Some(p) = pools().lock().unwrap().get(&id)
        && p.max != max.max(1)
    {
        tracing::warn!(
            pool = id,
            first = p.max,
            requested = max,
            "ignis_pg_open: same DSN opened with a different max; the first opener's applies"
        );
    }
    Some(id)
}

/// `ignis_pg_open`: no I/O; connections are made lazily up to `max`.
pub fn open(dsn: String, max: usize) -> u64 {
    if let Some(id) = pool_already_open_for_dsn(&dsn, max) {
        return id;
    }
    let id = NEXT.fetch_add(1, Ordering::Relaxed);
    let pool = Arc::new(Pool {
        dsn: dsn.clone(),
        idle: Mutex::new(Vec::new()),
        sem: Arc::new(Semaphore::new(max.max(1))),
        created: AtomicU64::new(0),
        max: max.max(1),
        breaker: Mutex::new(Breaker::default()),
    });
    by_dsn().lock().unwrap().insert(dsn, id);
    pools().lock().unwrap().insert(id, pool);
    id
}

/// A new connection. The spawned task owns the socket and ends when the client is dropped.
async fn connect(pool: &Pool) -> Result<Client, tokio_postgres::Error> {
    let (client, connection) = tokio_postgres::connect(&pool.dsn, NoTls).await?;
    tokio::spawn(async move {
        if let Err(e) = connection.await {
            tracing::debug!(error = %e, "pg connection ended");
        }
    });
    pool.created.fetch_add(1, Ordering::Relaxed);
    Ok(client)
}

/// `ignis_pg_acquire`: waits for a permit (pool exhausted) then reuses an idle connection or connects.
///
/// M4-2/B2: the wait is bounded by `IGNIS_PG_ACQUIRE_TIMEOUT_MS` and gated by the pool's breaker, so
/// a slow or dead PostgreSQL costs a fiber the ceiling once and nothing afterwards, instead of
/// stalling every fiber on every thread for as long as the database stays unhappy.
pub fn acquire(pool_id: u64, owner: usize) -> Fut {
    Box::pin(async move {
        let Some(pool) = pools().lock().unwrap().get(&pool_id).cloned() else { return failed("unknown pool".into()) };
        let admitted = pool.breaker.lock().unwrap().admit();
        if let Err(message) = admitted {
            return failed(message);
        }
        let ceiling = tuning().acquire_timeout;
        let outcome = match tokio::time::timeout(ceiling, lease_from(pool.clone(), owner)).await {
            Ok(outcome) => outcome,
            Err(_) => failed(format!("acquire timed out after {} ms (IGNIS_PG_ACQUIRE_TIMEOUT_MS)", ceiling.as_millis())),
        };
        pool.breaker.lock().unwrap().record(!matches!(outcome, Outcome::Failed(_)));
        outcome
    })
}

/// The unbounded half of `acquire`: a permit, then an idle connection or a new one. Cancelling it at
/// the ceiling drops the permit and any half-open socket with it.
async fn lease_from(pool: Arc<Pool>, owner: usize) -> Outcome {
    let permit = match pool.sem.clone().acquire_owned().await {
        Ok(p) => p,
        Err(_) => return failed("pool closed".into()),
    };
    let idle = pool.idle.lock().unwrap().pop();
    let connection = match idle {
        Some(c) if !c.client.is_closed() => c,
        _ => match connect(&pool).await {
            Ok(c) => Connection { client: c, stmts: HashMap::new() },
            Err(e) => return failed(format!("connect: {e}")),
        },
    };
    let id = NEXT.fetch_add(1, Ordering::Relaxed);
    leases()
        .lock()
        .unwrap()
        .insert(id, Arc::new(AsyncMutex::new(Some(Lease { pool, connection, _permit: permit, since: Instant::now() }))));
    owners().lock().unwrap().insert(id, owner);
    Outcome::Json(format!("{{\"lease\":{id}}}"))
}

/// Fast path for `ignis_pg_acquire`: a free permit and an idle connection mean no reactor hop.
/// With no idle connection the permit is dropped and `None` sends the caller down the async path.
/// The breaker does not gate this path: it never connects and never waits, and a closed connection
/// is popped and skipped, so during an outage the idle list drains and callers reach `acquire`.
pub fn try_acquire(pool_id: u64, owner: usize) -> Option<u64> {
    let pool = pools().lock().unwrap().get(&pool_id).cloned()?;
    let permit = pool.sem.clone().try_acquire_owned().ok()?;
    let connection = {
        let mut idle = pool.idle.lock().unwrap();
        loop {
            match idle.pop() {
                Some(c) if !c.client.is_closed() => break c,
                Some(_) => continue,
                None => return None,
            }
        }
    };
    let id = NEXT.fetch_add(1, Ordering::Relaxed);
    leases()
        .lock()
        .unwrap()
        .insert(id, Arc::new(AsyncMutex::new(Some(Lease { pool, connection, _permit: permit, since: Instant::now() }))));
    owners().lock().unwrap().insert(id, owner);
    Some(id)
}

/// `ignis_pg_query`: prepare, bind by the statement's parameter types, run; rows or the affected count.
pub fn query(lease_id: u64, sql: String, params_json: String) -> Fut {
    Box::pin(async move {
        let Some(slot) = leases().lock().unwrap().get(&lease_id).cloned() else { return failed("unknown or released lease".into()) };
        let mut guard = slot.lock().await;
        let Some(lease) = guard.as_mut() else { return failed("lease released".into()) };
        let params: Vec<Value> = match serde_json::from_str(&params_json) {
            Ok(Value::Array(a)) => a,
            Ok(_) => return failed("params must be a JSON array".into()),
            Err(e) => return failed(format!("params json: {e}")),
        };
        let stmt = match lease.connection.stmts.get(&sql) {
            Some(s) => s.clone(),
            None => match lease.connection.client.prepare(&sql).await {
                Ok(s) => {
                    if lease.connection.stmts.len() >= STMT_CACHE_MAX {
                        lease.connection.stmts.clear();
                    }
                    lease.connection.stmts.insert(sql.clone(), s.clone());
                    s
                }
                Err(e) => return failed(format!("prepare: {e}")),
            },
        };
        if stmt.params().len() != params.len() {
            return failed(format!("statement has {} parameter(s), {} given", stmt.params().len(), params.len()));
        }
        let mut bound: Vec<Box<dyn ToSql + Sync + Send>> = Vec::with_capacity(params.len());
        for (i, (v, ty)) in params.iter().zip(stmt.params()).enumerate() {
            match bind(v, ty) {
                Ok(b) => bound.push(b),
                Err(e) => return failed(format!("${}: {e}", i + 1)),
            }
        }
        let refs: Vec<&(dyn ToSql + Sync)> = bound.iter().map(|b| &**b as &(dyn ToSql + Sync)).collect();
        if stmt.columns().is_empty() {
            return match lease.connection.client.execute(&stmt, &refs).await {
                Ok(n) => Outcome::Json(json!({"rows": [], "affected": n}).to_string()),
                Err(e) => failed(format!("execute: {e}")),
            };
        }
        let rows = match lease.connection.client.query(&stmt, &refs).await {
            Ok(r) => r,
            Err(e) => return failed(format!("query: {e}")),
        };
        let mut out = Vec::with_capacity(rows.len());
        for row in &rows {
            match row_to_json(row) {
                Ok(v) => out.push(v),
                Err(e) => return failed(e),
            }
        }
        Outcome::Json(json!({"rows": out, "affected": rows.len()}).to_string())
    })
}

/// `ignis_pg_release`: `ROLLBACK; DISCARD ALL` (when `reset`) then back to idle; a failed reset closes the connection.
/// The permit is returned last, so the next acquire finds the connection already idle.
pub fn release(lease_id: u64, reset: bool) -> Fut {
    Box::pin(async move {
        owners().lock().unwrap().remove(&lease_id);
        let Some(slot) = leases().lock().unwrap().remove(&lease_id) else { return failed("unknown or released lease".into()) };
        let Some(lease) = slot.lock().await.take() else { return failed("lease released".into()) };
        let Lease { pool, connection, _permit, since } = lease;
        let held_ms = since.elapsed().as_millis() as u64;
        if held_ms >= lease_warn_ms() {
            tracing::warn!(lease = lease_id, held_ms, "pg lease held longer than IGNIS_PG_LEASE_WARN_MS");
        }
        if reset {
            match connection.client.batch_execute(RESET_SQL).await {
                Ok(()) => pool.idle.lock().unwrap().push(connection),
                Err(e) => tracing::warn!(error = %e, "pg reset failed; connection dropped"),
            }
        } else {
            pool.idle.lock().unwrap().push(connection);
        }
        drop(_permit);
        Outcome::Ready
    })
}

/// `ignis_pg_stats(pool)`: `(idle, created, available permits)` for VALIDATION tables.
pub fn stats(pool_id: u64) -> Option<(usize, u64, usize)> {
    pools()
        .lock()
        .unwrap()
        .get(&pool_id)
        .map(|p| (p.idle.lock().unwrap().len(), p.created.load(Ordering::Relaxed), p.sem.available_permits()))
}

/// M4-1: (age of the oldest live lease in ms, live leases held ≥ `IGNIS_PG_LEASE_WARN_MS`) for one pool.
/// A lease mid-query is behind its async mutex; `try_lock` skips it rather than block the PHP thread.
pub fn lease_ages(pool_id: u64) -> (u64, usize) {
    let warn = lease_warn_ms();
    let mut oldest = 0u64;
    let mut over = 0usize;
    let slots: Vec<_> = leases().lock().unwrap().values().cloned().collect();
    for slot in slots {
        if let Ok(guard) = slot.try_lock()
            && let Some(l) = guard.as_ref()
            && Arc::ptr_eq(&l.pool, &pools().lock().unwrap()[&pool_id])
        {
            let ms = l.since.elapsed().as_millis() as u64;
            oldest = oldest.max(ms);
            if ms >= warn {
                over += 1;
            }
        }
    }
    (oldest, over)
}

/// M4-4, every pool at once: (oldest live lease in ms, leases over the warn threshold, live leases).
/// Never blocks — `/_ignis/metrics` must answer while PHP threads are busy — so a locked slot counts
/// as live but not towards the oldest age.
pub fn lease_metrics() -> (u64, usize, usize) {
    let warn = lease_warn_ms();
    let (mut oldest, mut over, mut live) = (0u64, 0usize, 0usize);
    let slots: Vec<_> = leases().lock().unwrap().values().cloned().collect();
    for slot in slots {
        if let Ok(guard) = slot.try_lock()
            && let Some(l) = guard.as_ref()
        {
            live += 1;
            let ms = l.since.elapsed().as_millis() as u64;
            oldest = oldest.max(ms);
            if ms >= warn {
                over += 1;
            }
        }
    }
    (oldest, over, live)
}

/// `IGNIS_PG_LEASE_WARN_MS`, default 5000; read once.
fn lease_warn_ms() -> u64 {
    static V: OnceLock<u64> = OnceLock::new();
    *V.get_or_init(|| env_or("IGNIS_PG_LEASE_WARN_MS", 5000))
}

/// M4-2/B2, read once. The ceiling matches `IGNIS_PG_LEASE_WARN_MS`: waiting longer for a connection
/// than we are willing to let one be held is a stalled request, not a busy pool. Five failures are
/// enough that an ordinary connection blip does not trip the breaker, and the cooldown is one ceiling
/// — long enough that a down database is not re-probed per request, short enough that a recovered one
/// is back within a request or two. `IGNIS_PG_BREAKER_FAILURES=0` turns the breaker off.
struct Tuning {
    acquire_timeout: Duration,
    breaker_failures: u32,
    breaker_cooldown: Duration,
}

static TUNING: OnceLock<Tuning> = OnceLock::new();

fn tuning() -> &'static Tuning {
    TUNING.get_or_init(|| Tuning {
        acquire_timeout: Duration::from_millis(env_or("IGNIS_PG_ACQUIRE_TIMEOUT_MS", 5000)),
        breaker_failures: env_or("IGNIS_PG_BREAKER_FAILURES", 5),
        breaker_cooldown: Duration::from_millis(env_or("IGNIS_PG_BREAKER_COOLDOWN_MS", 5000)),
    })
}

fn env_or<T: std::str::FromStr>(name: &str, default: T) -> T {
    std::env::var(name).ok().and_then(|v| v.parse().ok()).unwrap_or(default)
}

/// The exact integer first; the float fallback runs only when that fails, so an out-of-range
/// value errors instead of silently saturating (a float-to-int `as` saturates).
fn integral_value(n: &serde_json::Number) -> Option<i64> {
    n.as_i64().or_else(|| n.as_f64().map(|f| f as i64))
}

fn bind(v: &Value, ty: &Type) -> Result<Box<dyn ToSql + Sync + Send>, String> {
    macro_rules! numeric {
        ($t:ty) => {{
            if v.is_null() {
                return Ok(Box::new(None::<$t>));
            }
            let n: $t = match v {
                Value::Number(n) => {
                    let whole = integral_value(n);
                    whole.and_then(|x| <$t>::try_from(x).ok()).ok_or("number out of range")?
                }
                Value::String(s) => s.parse::<$t>().map_err(|_| format!("'{s}' is not a {}", ty.name()))?,
                Value::Bool(b) => (*b as i64) as $t,
                _ => return Err(format!("cannot bind {v} as {}", ty.name())),
            };
            Ok(Box::new(Some(n)))
        }};
    }
    match *ty {
        Type::INT2 => numeric!(i16),
        Type::INT4 => numeric!(i32),
        Type::INT8 => numeric!(i64),
        Type::FLOAT4 => {
            if v.is_null() {
                return Ok(Box::new(None::<f32>));
            }
            let f = v.as_f64().or_else(|| v.as_str().and_then(|s| s.parse().ok())).ok_or("not a float")?;
            Ok(Box::new(Some(f as f32)))
        }
        Type::FLOAT8 => {
            if v.is_null() {
                return Ok(Box::new(None::<f64>));
            }
            let f = v.as_f64().or_else(|| v.as_str().and_then(|s| s.parse().ok())).ok_or("not a float")?;
            Ok(Box::new(Some(f)))
        }
        Type::BOOL => {
            if v.is_null() {
                return Ok(Box::new(None::<bool>));
            }
            let b = match v {
                Value::Bool(b) => *b,
                Value::Number(n) => n.as_i64() != Some(0),
                Value::String(s) => matches!(s.as_str(), "t" | "true" | "1" | "on" | "yes"),
                _ => return Err("not a bool".into()),
            };
            Ok(Box::new(Some(b)))
        }
        Type::TEXT | Type::VARCHAR | Type::NAME | Type::BPCHAR | Type::UNKNOWN => {
            if v.is_null() {
                return Ok(Box::new(None::<String>));
            }
            let s = match v {
                Value::String(s) => s.clone(),
                Value::Number(n) => n.to_string(),
                Value::Bool(b) => b.to_string(),
                other => other.to_string(),
            };
            Ok(Box::new(Some(s)))
        }
        Type::JSON | Type::JSONB => Ok(Box::new(if v.is_null() { None } else { Some(v.clone()) })),
        Type::BYTEA => match v {
            Value::Null => Ok(Box::new(None::<Vec<u8>>)),
            Value::String(s) => base64::engine::general_purpose::STANDARD
                .decode(s)
                .map(|b| Box::new(Some(b)) as Box<dyn ToSql + Sync + Send>)
                .map_err(|e| format!("bytea parameters are base64 strings: {e}")),
            _ => Err("bytea parameters are base64 strings".into()),
        },
        _ => Err(format!("unsupported parameter type {} (cast it in SQL, e.g. $n::text)", ty.name())),
    }
}

fn row_to_json(row: &Row) -> Result<Value, String> {
    let mut m = serde_json::Map::with_capacity(row.len());
    for (i, col) in row.columns().iter().enumerate() {
        let v = col_to_json(row, i, col.type_()).map_err(|e| format!("column '{}': {e}", col.name()))?;
        m.insert(col.name().to_string(), v);
    }
    Ok(Value::Object(m))
}

fn col_to_json(row: &Row, i: usize, ty: &Type) -> Result<Value, String> {
    fn get<'a, T: tokio_postgres::types::FromSql<'a>>(row: &'a Row, i: usize) -> Result<Option<T>, String> {
        row.try_get::<_, Option<T>>(i).map_err(|e| e.to_string())
    }
    Ok(match *ty {
        Type::BOOL => json!(get::<bool>(row, i)?),
        Type::INT2 => json!(get::<i16>(row, i)?),
        Type::INT4 => json!(get::<i32>(row, i)?),
        Type::INT8 => json!(get::<i64>(row, i)?),
        Type::OID => json!(get::<u32>(row, i)?),
        Type::FLOAT4 => json!(get::<f32>(row, i)?),
        Type::FLOAT8 => json!(get::<f64>(row, i)?),
        Type::TEXT | Type::VARCHAR | Type::NAME | Type::BPCHAR | Type::UNKNOWN => json!(get::<String>(row, i)?),
        Type::JSON | Type::JSONB => json!(get::<Value>(row, i)?),
        Type::BYTEA => json!(get::<Vec<u8>>(row, i)?.map(|b| base64::engine::general_purpose::STANDARD.encode(b))),
        Type::VOID => Value::Null,
        _ => return Err(format!("unsupported type {} (oid {}); cast it to text in SQL", ty.name(), ty.oid())),
    })
}

/// M4-11 (V-42): a PHP thread is ending — return every lease it still holds, and how many that was.
/// The reset runs on the runtime, so nothing here waits on the dying thread or on a query in flight.
pub fn release_owned_by(owner: usize, rt: &tokio::runtime::Handle) -> usize {
    let ids: Vec<u64> = owners().lock().unwrap().iter().filter(|(_, o)| **o == owner).map(|(id, _)| *id).collect();
    for &id in &ids {
        rt.spawn(release(id, true));
    }
    if !ids.is_empty() {
        tracing::warn!(leases = ids.len(), "php thread ended holding pg leases; reset and returned to the pool");
    }
    ids.len()
}

#[cfg(test)]
mod tests {
    use super::*;
    use bytes::BytesMut;
    use tokio_postgres::types::IsNull;

    /// What the wire would carry for `value` bound as `ty`: `None` for SQL NULL.
    fn encode(value: &Value, ty: &Type) -> Result<Option<BytesMut>, String> {
        let bound = bind(value, ty)?;
        let mut buffer = BytesMut::new();
        match bound.to_sql_checked(ty, &mut buffer) {
            Ok(IsNull::No) => Ok(Some(buffer)),
            Ok(IsNull::Yes) => Ok(None),
            Err(err) => Err(err.to_string()),
        }
    }

    #[test]
    fn int4_binds_numbers_strings_bools_and_null() {
        assert_eq!(encode(&json!(5), &Type::INT4).unwrap().unwrap()[..], [0, 0, 0, 5]);
        assert_eq!(encode(&json!("5"), &Type::INT4).unwrap().unwrap()[..], [0, 0, 0, 5]);
        assert_eq!(encode(&json!(true), &Type::INT4).unwrap().unwrap()[..], [0, 0, 0, 1]);
        assert_eq!(encode(&json!(false), &Type::INT4).unwrap().unwrap()[..], [0, 0, 0, 0]);
        assert_eq!(encode(&Value::Null, &Type::INT4).unwrap(), None);
        assert_eq!(encode(&json!(-1), &Type::INT8).unwrap().unwrap()[..], [255; 8]);
    }

    /// Until 2026-09-17 these two saturated to 32767 / -32768 and were written to the row: the
    /// range check ran before the float fallback, and a float-to-int `as` saturates. A JSON
    /// number and a JSON string of the same value must both be refused, in range or not.
    #[test]
    fn int2_rejects_an_out_of_range_value_whichever_json_type_it_arrives_as() {
        let from_string = bind(&json!("70000"), &Type::INT2).unwrap_err();
        assert!(from_string.contains("is not a int2"), "{from_string}");

        assert!(bind(&json!(70000), &Type::INT2).unwrap_err().contains("number out of range"));
        assert!(bind(&json!(-70000), &Type::INT2).unwrap_err().contains("number out of range"));

        assert_eq!(encode(&json!(32767), &Type::INT2).unwrap().unwrap()[..], [0x7f, 0xff]);
        assert_eq!(encode(&json!(7.9), &Type::INT2).unwrap().unwrap()[..], [0x00, 0x07]);
    }

    #[test]
    fn text_and_bool_coerce_from_any_scalar() {
        assert_eq!(encode(&json!(42), &Type::TEXT).unwrap().unwrap()[..], b"42"[..]);
        assert_eq!(encode(&json!(true), &Type::VARCHAR).unwrap().unwrap()[..], b"true"[..]);
        assert_eq!(encode(&Value::Null, &Type::TEXT).unwrap(), None);

        assert_eq!(encode(&json!("yes"), &Type::BOOL).unwrap().unwrap()[..], [1]);
        assert_eq!(encode(&json!("nope"), &Type::BOOL).unwrap().unwrap()[..], [0]);
        assert_eq!(encode(&json!(0), &Type::BOOL).unwrap().unwrap()[..], [0]);
        assert_eq!(encode(&json!(3), &Type::BOOL).unwrap().unwrap()[..], [1]);
        assert!(bind(&json!([1]), &Type::BOOL).unwrap_err().contains("not a bool"));
    }

    #[test]
    fn bytea_is_base64_and_says_so_when_it_is_not() {
        assert_eq!(encode(&json!("aGk="), &Type::BYTEA).unwrap().unwrap()[..], b"hi"[..]);
        let err = bind(&json!("!!!"), &Type::BYTEA).unwrap_err();
        assert!(err.starts_with("bytea parameters are base64 strings: "), "{err}");
        assert!(bind(&json!(1), &Type::BYTEA).unwrap_err().contains("base64"));
    }

    #[test]
    fn an_unsupported_parameter_type_tells_the_caller_to_cast_it() {
        let err = bind(&json!("2026-09-17"), &Type::DATE).unwrap_err();
        assert!(err.contains("unsupported parameter type date"), "{err}");
        assert!(err.contains("cast it in SQL"), "{err}");
    }

    /// V-43: `--threads 3` used to mint three pools of `max` each for one DSN.
    #[test]
    fn open_is_one_pool_per_dsn_and_does_no_io() {
        let dsn = "postgres://nobody@240.0.0.1:1/ignis-test";
        let started = Instant::now();
        let first = open(dsn.into(), 4);
        let again = open(dsn.into(), 9);
        let other = open(format!("{dsn}-other"), 4);
        assert_eq!(first, again, "same DSN must share one pool");
        assert_ne!(first, other);
        assert!(started.elapsed() < Duration::from_millis(100), "open blocked: {:?}", started.elapsed());
        assert_eq!(stats(first), Some((0, 0, 4)), "open must not connect, and keeps the first opener's max");
        assert_eq!(stats(other), Some((0, 0, 4)));
    }

    /// A listener that completes the TCP handshake and then answers nothing: PostgreSQL slow rather
    /// than down, which is the case an unbounded acquire waits on for ever. The listener has to stay
    /// alive for the length of the test, so the caller keeps it.
    fn black_hole() -> (std::net::TcpListener, String) {
        let listener = std::net::TcpListener::bind("127.0.0.1:0").expect("bind");
        let port = listener.local_addr().expect("local_addr").port();
        (listener, format!("postgres://ignis@127.0.0.1:{port}/ignis-test"))
    }

    fn tune(acquire_timeout_ms: u64, breaker_failures: u32, breaker_cooldown_ms: u64) {
        let tuning = Tuning {
            acquire_timeout: Duration::from_millis(acquire_timeout_ms),
            breaker_failures,
            breaker_cooldown: Duration::from_millis(breaker_cooldown_ms),
        };
        assert!(TUNING.set(tuning).is_ok(), "tuning was already read; each test needs its own process (nextest)");
    }

    async fn acquire_failure(pool: u64) -> (String, Duration) {
        let started = Instant::now();
        let outcome = acquire(pool, 0).await;
        let elapsed = started.elapsed();
        let Outcome::Failed(message) = outcome else { panic!("a black hole answered an acquire") };
        (message, elapsed)
    }

    /// M4-2/B2: the bulkhead. Before this, the acquire below never returned.
    #[tokio::test]
    async fn an_acquire_gives_up_at_the_ceiling_instead_of_waiting_on_a_slow_database() {
        tune(300, 0, 0);
        let (_listener, dsn) = black_hole();
        let pool = open(dsn, 2);

        let (message, elapsed) = acquire_failure(pool).await;
        assert!(message.contains("acquire timed out after 300 ms"), "{message}");
        assert!(elapsed >= Duration::from_millis(300), "gave up early: {elapsed:?}");
        assert!(elapsed < Duration::from_secs(2), "the ceiling did not bound the wait: {elapsed:?}");
    }

    /// M4-2/B2: the breaker. The point of the fast-fail assertion is that a down dependency costs a
    /// fiber nothing rather than the ceiling, every request, for as long as it stays down.
    #[tokio::test]
    async fn the_breaker_opens_on_consecutive_failures_and_lets_one_probe_through_after_the_cooldown() {
        tune(200, 2, 400);
        let (_listener, dsn) = black_hole();
        let pool = open(dsn, 4);

        for attempt in 0..2 {
            let (message, _) = acquire_failure(pool).await;
            assert!(message.contains("acquire timed out"), "attempt {attempt}: {message}");
        }

        let (message, elapsed) = acquire_failure(pool).await;
        assert!(message.contains("circuit breaker open after 2 consecutive failures"), "{message}");
        assert!(elapsed < Duration::from_millis(50), "an open breaker must fail fast: {elapsed:?}");

        tokio::time::sleep(Duration::from_millis(450)).await;
        let (message, elapsed) = acquire_failure(pool).await;
        assert!(message.contains("acquire timed out"), "the probe never reached the database: {message}");
        assert!(elapsed >= Duration::from_millis(200), "the probe did not wait for the ceiling: {elapsed:?}");
    }

    #[test]
    fn stats_and_lease_metrics_on_an_untouched_process() {
        assert_eq!(stats(999_999), None);
        assert_eq!(lease_metrics(), (0, 0, 0));
        let Outcome::Failed(message) = failed("boom".into()) else { panic!("not a failure") };
        assert_eq!(message, "pg: boom");
    }
}
