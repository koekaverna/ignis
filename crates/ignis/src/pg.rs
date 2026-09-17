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

use base64::Engine as _;
use serde_json::{Value, json};
use tokio::sync::{Mutex as AsyncMutex, OwnedSemaphorePermit, Semaphore};
use tokio_postgres::types::{ToSql, Type};
use tokio_postgres::{Client, NoTls, Row, Statement};

use crate::reactor::Outcome;

/// A connection plus its prepared-statement cache (kept across leases because the
/// session reset below deliberately leaves prepared statements alone).
struct Conn {
    client: Client,
    stmts: HashMap<String, Statement>,
}

struct Pool {
    dsn: String,
    idle: Mutex<Vec<Conn>>,
    sem: Arc<Semaphore>,
    created: AtomicU64,
    /// M4-12: the capacity the first opener asked for, so a later opener with a different `max`
    /// can be warned rather than silently getting the first one's.
    max: usize,
}

struct Lease {
    pool: Arc<Pool>,
    conn: Conn,
    _permit: OwnedSemaphorePermit,
    /// M4-1: when the lease was handed out, so a held connection is visible (oldest age in
    /// `stats`) and logged (`release` warns past `IGNIS_PG_LEASE_WARN_MS`). Before this nothing
    /// tracked hold time at all — the owner's "will we see it in the logs?" was answered no.
    since: std::time::Instant,
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
fn failed(msg: String) -> Outcome {
    Outcome::Failed(format!("pg: {msg}"))
}

/// `ignis_pg_open`: no I/O; connections are made lazily up to `max`.
pub fn open(dsn: String, max: usize) -> u64 {
    // M4-12: one pool per DSN per process. Every worker thread runs the same script and calls
    // this; before, each call minted a pool, so an app got threads × max connections (V-43).
    if let Some(&id) = by_dsn().lock().unwrap().get(&dsn) {
        if let Some(p) = pools().lock().unwrap().get(&id)
            && p.max != max.max(1)
        {
            tracing::warn!(pool = id, first = p.max, requested = max, "ignis_pg_open: same DSN opened with a different max; the first opener's applies");
        }
        return id;
    }
    let id = NEXT.fetch_add(1, Ordering::Relaxed);
    let pool = Arc::new(Pool { dsn: dsn.clone(), idle: Mutex::new(Vec::new()), sem: Arc::new(Semaphore::new(max.max(1))), created: AtomicU64::new(0), max: max.max(1) });
    by_dsn().lock().unwrap().insert(dsn, id);
    pools().lock().unwrap().insert(id, pool);
    id
}

async fn connect(pool: &Pool) -> Result<Client, tokio_postgres::Error> {
    let (client, conn) = tokio_postgres::connect(&pool.dsn, NoTls).await?;
    // The connection task owns the socket; it ends when the client is dropped.
    tokio::spawn(async move {
        if let Err(e) = conn.await {
            tracing::debug!(error = %e, "pg connection ended");
        }
    });
    pool.created.fetch_add(1, Ordering::Relaxed);
    Ok(client)
}

/// `ignis_pg_acquire`: waits for a permit (pool exhausted) then reuses an idle connection or connects.
pub fn acquire(pool_id: u64, owner: usize) -> Fut {
    Box::pin(async move {
        let Some(pool) = pools().lock().unwrap().get(&pool_id).cloned() else { return failed("unknown pool".into()) };
        let permit = match pool.sem.clone().acquire_owned().await {
            Ok(p) => p,
            Err(_) => return failed("pool closed".into()),
        };
        let idle = pool.idle.lock().unwrap().pop();
        let conn = match idle {
            Some(c) if !c.client.is_closed() => c,
            _ => match connect(&pool).await {
                Ok(c) => Conn { client: c, stmts: HashMap::new() },
                Err(e) => return failed(format!("connect: {e}")),
            },
        };
        let id = NEXT.fetch_add(1, Ordering::Relaxed);
        leases().lock().unwrap().insert(id, Arc::new(AsyncMutex::new(Some(Lease { pool, conn, _permit: permit, since: std::time::Instant::now() }))));
        owners().lock().unwrap().insert(id, owner);
        Outcome::Json(format!("{{\"lease\":{id}}}"))
    })
}

/// Fast path for `ignis_pg_acquire`: a free permit and an idle connection mean no reactor hop.
pub fn try_acquire(pool_id: u64, owner: usize) -> Option<u64> {
    let pool = pools().lock().unwrap().get(&pool_id).cloned()?;
    let permit = pool.sem.clone().try_acquire_owned().ok()?;
    let conn = {
        let mut idle = pool.idle.lock().unwrap();
        loop {
            match idle.pop() {
                Some(c) if !c.client.is_closed() => break c,
                Some(_) => continue,
                None => return None, // permit dropped: the async path will connect
            }
        }
    };
    let id = NEXT.fetch_add(1, Ordering::Relaxed);
    leases().lock().unwrap().insert(id, Arc::new(AsyncMutex::new(Some(Lease { pool, conn, _permit: permit, since: std::time::Instant::now() }))));
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
        let stmt = match lease.conn.stmts.get(&sql) {
            Some(s) => s.clone(),
            None => match lease.conn.client.prepare(&sql).await {
                Ok(s) => {
                    if lease.conn.stmts.len() >= STMT_CACHE_MAX {
                        lease.conn.stmts.clear();
                    }
                    lease.conn.stmts.insert(sql.clone(), s.clone());
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
            return match lease.conn.client.execute(&stmt, &refs).await {
                Ok(n) => Outcome::Json(json!({"rows": [], "affected": n}).to_string()),
                Err(e) => failed(format!("execute: {e}")),
            };
        }
        let rows = match lease.conn.client.query(&stmt, &refs).await {
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
pub fn release(lease_id: u64, reset: bool) -> Fut {
    Box::pin(async move {
        owners().lock().unwrap().remove(&lease_id);
        let Some(slot) = leases().lock().unwrap().remove(&lease_id) else { return failed("unknown or released lease".into()) };
        let Some(lease) = slot.lock().await.take() else { return failed("lease released".into()) };
        let Lease { pool, conn, _permit, since } = lease;
        let held_ms = since.elapsed().as_millis() as u64;
        if held_ms >= lease_warn_ms() {
            tracing::warn!(lease = lease_id, held_ms, "pg lease held longer than IGNIS_PG_LEASE_WARN_MS");
        }
        if reset {
            match conn.client.batch_execute(RESET_SQL).await {
                Ok(()) => pool.idle.lock().unwrap().push(conn),
                Err(e) => tracing::warn!(error = %e, "pg reset failed; connection dropped"),
            }
        } else {
            pool.idle.lock().unwrap().push(conn);
        }
        // Dropping the permit here lets the next acquire proceed with the connection already idle.
        drop(_permit);
        Outcome::Ready
    })
}

/// `ignis_pg_stats(pool)`: `(idle, created, available permits)` for VALIDATION tables.
pub fn stats(pool_id: u64) -> Option<(usize, u64, usize)> {
    pools().lock().unwrap().get(&pool_id).map(|p| (p.idle.lock().unwrap().len(), p.created.load(Ordering::Relaxed), p.sem.available_permits()))
}

/// M4-1: (age of the oldest live lease in ms, live leases held ≥ `IGNIS_PG_LEASE_WARN_MS`) for a
/// pool. A lease mid-query is behind its async mutex; `try_lock` skips it rather than block the
/// PHP thread, so a lease inside a long query is counted from the moment it is free to inspect —
/// which is exactly when its age is the interesting number.
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

/// Pool-wide lease numbers for `/_ignis/metrics` (M4-4): (oldest live lease in ms, leases older
/// than the warn threshold, live leases). Unlike `lease_ages()` this asks about every pool at once
/// and never blocks: a lease whose slot is locked right now is counted as live and skipped for
/// age, because the metrics endpoint must answer while PHP threads are busy, not wait for them.
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
    *V.get_or_init(|| std::env::var("IGNIS_PG_LEASE_WARN_MS").ok().and_then(|v| v.parse().ok()).unwrap_or(5000))
}

fn bind(v: &Value, ty: &Type) -> Result<Box<dyn ToSql + Sync + Send>, String> {
    macro_rules! num {
        ($t:ty) => {{
            if v.is_null() {
                return Ok(Box::new(None::<$t>));
            }
            let n: $t = match v {
                Value::Number(n) => n.as_i64().and_then(|x| <$t>::try_from(x).ok()).or_else(|| n.as_f64().map(|f| f as $t)).ok_or("number out of range")?,
                Value::String(s) => s.parse::<$t>().map_err(|_| format!("'{s}' is not a {}", ty.name()))?,
                Value::Bool(b) => (*b as i64) as $t,
                _ => return Err(format!("cannot bind {v} as {}", ty.name())),
            };
            Ok(Box::new(Some(n)))
        }};
    }
    match *ty {
        Type::INT2 => num!(i16),
        Type::INT4 => num!(i32),
        Type::INT8 => num!(i64),
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

/// M4-11 (V-42): a PHP thread is ending — return every lease it still holds. The reset and the
/// permit return run on the runtime, so nothing here waits on the dying thread; a lease whose
/// query is still in flight is released the moment that query finishes (`release` takes the
/// lease's async mutex). Returns how many were reclaimed.
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
