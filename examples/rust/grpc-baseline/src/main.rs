//! Same framing path as Ignis (`tonic::server::Grpc` + raw bytes codec on a hyper auto listener),
//! but the handler is Rust: a fixed HelloReply. Whatever this measures is the ceiling; the
//! difference to `bench/e10-grpc.sh` is the PHP hop (reactor round trip + Proto codec + 2 zifs).
use std::future::{Ready, ready};

use bytes::{Buf, BufMut, Bytes};
use hyper_util::rt::{TokioExecutor, TokioIo};
use hyper_util::server::conn::auto;
use tonic::codec::{Codec, DecodeBuf, Decoder, EncodeBuf, Encoder};
use tonic::server::UnaryService;
use tonic::Status;

#[derive(Clone, Copy, Default)]
struct RawCodec;
struct Enc;
struct Dec;
impl Codec for RawCodec {
    type Encode = Bytes;
    type Decode = Bytes;
    type Encoder = Enc;
    type Decoder = Dec;
    fn encoder(&mut self) -> Enc {
        Enc
    }
    fn decoder(&mut self) -> Dec {
        Dec
    }
}
impl Encoder for Enc {
    type Item = Bytes;
    type Error = Status;
    fn encode(&mut self, item: Bytes, dst: &mut EncodeBuf<'_>) -> Result<(), Status> {
        dst.put(item);
        Ok(())
    }
}
impl Decoder for Dec {
    type Item = Bytes;
    type Error = Status;
    fn decode(&mut self, src: &mut DecodeBuf<'_>) -> Result<Option<Bytes>, Status> {
        let n = src.remaining();
        Ok(Some(src.copy_to_bytes(n)))
    }
}

struct Hello;
impl UnaryService<Bytes> for Hello {
    type Response = Bytes;
    type Future = Ready<Result<tonic::Response<Bytes>, Status>>;
    fn call(&mut self, _req: tonic::Request<Bytes>) -> Self::Future {
        // HelloReply{message = "hello ada"} pre-encoded.
        ready(Ok(tonic::Response::new(Bytes::from_static(b"\x0a\x09hello ada"))))
    }
}

#[tokio::main(flavor = "multi_thread", worker_threads = 2)]
async fn main() {
    let addr = std::env::var("ADDR").unwrap_or_else(|_| "127.0.0.1:8090".into());
    let listener = tokio::net::TcpListener::bind(&addr).await.unwrap();
    eprintln!("grpc-baseline on {addr}");
    loop {
        let (stream, _) = listener.accept().await.unwrap();
        let _ = stream.set_nodelay(true);
        tokio::spawn(async move {
            let svc = hyper::service::service_fn(|req: http::Request<hyper::body::Incoming>| async move {
                let mut g = tonic::server::Grpc::new(RawCodec);
                Ok::<_, std::convert::Infallible>(g.unary(Hello, req).await)
            });
            let _ = auto::Builder::new(TokioExecutor::new()).serve_connection(TokioIo::new(stream), svc).await;
        });
    }
}
