//! hyper 1.x front door. Runs entirely on tokio; hands each request to the
//! reactor as plain data and awaits the PHP answer on a oneshot.
use std::net::SocketAddr;
use std::sync::Arc;

use anyhow::{Context, Result};
use bytes::Bytes;
use http_body_util::{BodyExt, Full};
use hyper::body::Incoming;
use hyper::service::service_fn;
use hyper::{Request, Response, StatusCode};
use hyper_util::rt::{TokioExecutor, TokioIo};
use hyper_util::server::conn::auto;
use tokio::net::TcpListener;

use crate::reactor::{HttpRequest, Reactor};

/// Binds `addr` and serves it forever on the runtime. Returns after the
/// listener is bound so the caller knows the port is live.
pub fn start(rt: &tokio::runtime::Handle, reactor: Arc<Reactor>, addr: &str) -> Result<SocketAddr> {
    let addr: SocketAddr = addr.parse().with_context(|| format!("bad listen address {addr:?}"))?;
    let listener = rt.block_on(TcpListener::bind(addr)).with_context(|| format!("bind {addr}"))?;
    let local = listener.local_addr()?;
    reactor.server_started();
    rt.spawn(async move {
        loop {
            let (stream, _peer) = match listener.accept().await {
                Ok(x) => x,
                Err(e) => {
                    tracing::warn!(error = %e, "accept failed");
                    continue;
                }
            };
            let _ = stream.set_nodelay(true);
            let reactor = reactor.clone();
            tokio::spawn(async move {
                let svc = service_fn(move |req| handle(reactor.clone(), req));
                let builder = auto::Builder::new(TokioExecutor::new());
                if let Err(e) = builder.serve_connection(TokioIo::new(stream), svc).await {
                    tracing::debug!(error = %e, "connection ended with error");
                }
            });
        }
    });
    Ok(local)
}

async fn handle(reactor: Arc<Reactor>, req: Request<Incoming>) -> Result<Response<Full<Bytes>>, hyper::Error> {
    let (parts, body) = req.into_parts();
    let body = match body.collect().await {
        Ok(c) => c.to_bytes(),
        Err(e) => {
            tracing::debug!(error = %e, "body read failed");
            return Ok(simple(StatusCode::BAD_REQUEST, "bad request body\n"));
        }
    };
    let headers = parts
        .headers
        .iter()
        .map(|(k, v)| (k.as_str().to_string(), String::from_utf8_lossy(v.as_bytes()).into_owned()))
        .collect();
    let uri = parts.uri.path_and_query().map(|p| p.as_str().to_string()).unwrap_or_else(|| "/".into());
    let rx = reactor.deliver_request(HttpRequest { method: parts.method.as_str().to_string(), uri, headers, body });
    match rx.await {
        Ok(r) => {
            let mut b = Response::builder().status(r.status);
            for (k, v) in r.headers {
                b = b.header(k, v);
            }
            Ok(b.body(Full::new(r.body)).unwrap_or_else(|_| simple(StatusCode::INTERNAL_SERVER_ERROR, "bad response headers\n")))
        }
        // PHP dropped the responder without answering (handler crashed hard).
        Err(_) => Ok(simple(StatusCode::INTERNAL_SERVER_ERROR, "no response from php\n")),
    }
}

fn simple(status: StatusCode, msg: &'static str) -> Response<Full<Bytes>> {
    Response::builder().status(status).header("content-type", "text/plain").body(Full::new(Bytes::from_static(msg.as_bytes()))).unwrap()
}
