//! E10 (ADR-0014): gRPC on the shared hyper/h2 listener with an opaque-bytes codec.
//!
//! Server: `http.rs` hands gRPC requests to [`serve`], which frames them with
//! `tonic::server::Grpc` and dispatches to PHP as an ordinary request whose body
//! is the request message. PHP answers with `ignis_grpc_send` / `ignis_grpc_end`
//! (reactor stream channels). Client: [`call`] / [`recv`] are `Op::Custom` futures
//! over per-URL channels. No `.proto` is compiled here; PHP owns the encoding.

use std::collections::HashMap;
use std::future::{Future, Ready, ready};
use std::pin::Pin;
use std::sync::{Arc, Mutex, OnceLock};
use std::task::{Context, Poll};

use bytes::{Buf, BufMut, Bytes};
use http_body_util::{BodyExt, Full};
use tonic::codec::{Codec, DecodeBuf, Decoder, EncodeBuf, Encoder};

use tonic::server::ServerStreamingService;
use tonic::transport::{Channel, Endpoint};
use tonic::{Code, Status, Streaming};

use crate::reactor::{GrpcMsg, HttpRequest, Outcome, Reactor};

/// Messages cross tonic as raw `Bytes`; PHP encodes/decodes protobuf.
#[derive(Clone, Copy, Default)]
pub struct RawCodec;

pub struct RawEncoder;
pub struct RawDecoder;

impl Codec for RawCodec {
    type Encode = Bytes;
    type Decode = Bytes;
    type Encoder = RawEncoder;
    type Decoder = RawDecoder;
    fn encoder(&mut self) -> RawEncoder {
        RawEncoder
    }
    fn decoder(&mut self) -> RawDecoder {
        RawDecoder
    }
}

impl Encoder for RawEncoder {
    type Item = Bytes;
    type Error = Status;
    fn encode(&mut self, item: Bytes, dst: &mut EncodeBuf<'_>) -> Result<(), Status> {
        dst.put(item);
        Ok(())
    }
}

impl Decoder for RawDecoder {
    type Item = Bytes;
    type Error = Status;
    fn decode(&mut self, src: &mut DecodeBuf<'_>) -> Result<Option<Bytes>, Status> {
        let n = src.remaining();
        Ok(Some(src.copy_to_bytes(n)))
    }
}

/// True for requests that must be framed as gRPC.
pub fn is_grpc(req: &http::Request<hyper::body::Incoming>) -> bool {
    is_grpc_parts(req)
}

fn is_grpc_parts<B>(req: &http::Request<B>) -> bool {
    req.method() == http::Method::POST
        && req.headers().get(http::header::CONTENT_TYPE).and_then(|v| v.to_str().ok()).is_some_and(|ct| ct.starts_with("application/grpc"))
}

/// The response stream PHP fills through the reactor; dropping it before the
/// end cancels the request fiber (ADR-0009, same path as HTTP).
pub struct PhpStream {
    reactor: Arc<Reactor>,
    id: u64,
    rx: tokio::sync::mpsc::UnboundedReceiver<GrpcMsg>,
    done: bool,
}

impl futures_core::Stream for PhpStream {
    type Item = Result<Bytes, Status>;
    fn poll_next(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Option<Self::Item>> {
        match self.rx.poll_recv(cx) {
            Poll::Pending => Poll::Pending,
            Poll::Ready(Some(Ok(b))) => Poll::Ready(Some(Ok(b))),
            Poll::Ready(Some(Err((code, msg)))) => {
                self.done = true;
                Poll::Ready(Some(Err(Status::new(Code::from_i32(code), msg))))
            }
            Poll::Ready(None) => {
                self.done = true;
                Poll::Ready(None)
            }
        }
    }
}

impl Drop for PhpStream {
    fn drop(&mut self) {
        if !self.done {
            self.reactor.cancel_request(self.id);
        }
    }
}

/// One service for every method: PHP routes on the path.
struct PhpGrpc {
    reactor: Arc<Reactor>,
    uri: String,
    headers: Vec<(String, String)>,
}

impl ServerStreamingService<Bytes> for PhpGrpc {
    type Response = Bytes;
    type ResponseStream = PhpStream;
    type Future = Ready<Result<tonic::Response<PhpStream>, Status>>;
    fn call(&mut self, request: tonic::Request<Bytes>) -> Self::Future {
        let req = HttpRequest {
            method: "POST".to_string(),
            uri: std::mem::take(&mut self.uri),
            headers: std::mem::take(&mut self.headers),
            body: request.into_inner(),
        };
        let (id, rx) = self.reactor.deliver_stream_request(req);
        ready(Ok(tonic::Response::new(PhpStream { reactor: self.reactor.clone(), id, rx, done: false })))
    }
}

/// Serve one gRPC request (unary or server-streaming: PHP decides how many messages to send).
pub async fn serve(reactor: Arc<Reactor>, req: http::Request<hyper::body::Incoming>) -> http::Response<tonic::body::Body> {
    let uri = req.uri().path_and_query().map(|p| p.as_str().to_string()).unwrap_or_else(|| "/".into());
    let headers = req.headers().iter().map(|(k, v)| (k.as_str().to_string(), String::from_utf8_lossy(v.as_bytes()).into_owned())).collect();
    let svc = PhpGrpc { reactor, uri, headers };
    let mut grpc = tonic::server::Grpc::new(RawCodec);
    grpc.server_streaming(svc, req).await
}

/// Wrap a plain HTTP body so one hyper service can return both kinds of response.
pub fn plain_body(bytes: Bytes) -> tonic::body::Body {
    tonic::body::Body::new(Full::new(bytes).map_err(|never| match never {}))
}

// ---------------------------------------------------------------- client side

static CHANNELS: OnceLock<Mutex<HashMap<String, Channel>>> = OnceLock::new();
/// A server-streaming call in flight, shared with the fiber reading it.
type OpenStream = Arc<tokio::sync::Mutex<Streaming<Bytes>>>;

static STREAMS: OnceLock<Mutex<HashMap<u64, OpenStream>>> = OnceLock::new();
static NEXT_STREAM: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(1);

fn channel(url: &str) -> Result<Channel, Status> {
    let map = CHANNELS.get_or_init(|| Mutex::new(HashMap::new()));
    let mut m = map.lock().unwrap();
    if let Some(c) = m.get(url) {
        return Ok(c.clone());
    }
    let ep = Endpoint::from_shared(url.to_string()).map_err(|e| Status::invalid_argument(format!("bad url: {e}")))?;
    let c = ep.connect_lazy();
    m.insert(url.to_string(), c.clone());
    Ok(c)
}

fn failed(what: &str, s: Status) -> Outcome {
    Outcome::Failed(format!("grpc {what}: code={} {}", s.code() as i32, s.message()))
}

/// `Op::Custom` future for `ignis_grpc_call`: unary → `Blob(Some(reply))`;
/// server-streaming → `Json({"stream": handle})`.
pub fn call(url: String, path: String, msg: Bytes, streaming: bool) -> Pin<Box<dyn Future<Output = Outcome> + Send>> {
    Box::pin(async move {
        let ch = match channel(&url) {
            Ok(c) => c,
            Err(s) => return failed("channel", s),
        };
        let path: http::uri::PathAndQuery = match path.parse() {
            Ok(p) => p,
            Err(e) => return Outcome::Failed(format!("grpc path: {e}")),
        };
        let mut g = tonic::client::Grpc::new(ch);
        if let Err(s) = g.ready().await {
            return failed("ready", Status::unavailable(s.to_string()));
        }
        if streaming {
            match g.server_streaming(tonic::Request::new(msg), path, RawCodec).await {
                Ok(resp) => {
                    let id = NEXT_STREAM.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
                    STREAMS
                        .get_or_init(|| Mutex::new(HashMap::new()))
                        .lock()
                        .unwrap()
                        .insert(id, Arc::new(tokio::sync::Mutex::new(resp.into_inner())));
                    Outcome::Json(format!("{{\"stream\":{id}}}"))
                }
                Err(s) => failed("server_streaming", s),
            }
        } else {
            match g.unary(tonic::Request::new(msg), path, RawCodec).await {
                Ok(resp) => Outcome::Blob(Some(resp.into_inner())),
                Err(s) => failed("unary", s),
            }
        }
    })
}

/// `Op::Custom` future for `ignis_grpc_recv`: next message → `Blob(Some)`, end → `Blob(None)`.
pub fn recv(handle: u64) -> Pin<Box<dyn Future<Output = Outcome> + Send>> {
    Box::pin(async move {
        let map = STREAMS.get_or_init(|| Mutex::new(HashMap::new()));
        let Some(s) = map.lock().unwrap().get(&handle).cloned() else {
            return Outcome::Failed("grpc recv: unknown stream".into());
        };
        let r = s.lock().await.message().await;
        match r {
            Ok(Some(b)) => Outcome::Blob(Some(b)),
            Ok(None) => {
                map.lock().unwrap().remove(&handle);
                Outcome::Blob(None)
            }
            Err(st) => {
                map.lock().unwrap().remove(&handle);
                failed("recv", st)
            }
        }
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn is_grpc_needs_post_and_content_type() {
        let mk = |m: &str, ct: Option<&str>| {
            let mut b = http::Request::builder().method(m).uri("/pkg.Svc/M");
            if let Some(ct) = ct {
                b = b.header("content-type", ct);
            }
            b.body(()).unwrap()
        };
        assert!(is_grpc_parts(&mk("POST", Some("application/grpc"))));
        assert!(is_grpc_parts(&mk("POST", Some("application/grpc+proto"))));
        assert!(!is_grpc_parts(&mk("GET", Some("application/grpc"))));
        assert!(!is_grpc_parts(&mk("POST", Some("application/json"))));
        assert!(!is_grpc_parts(&mk("POST", None)));
    }
}
