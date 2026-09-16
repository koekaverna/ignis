# temporal-probe (E9 step 1, V-18)

Standalone crate (not a workspace member: it pulls ~600 crates from `temporalio/sdk-core` git).
Build: `cd examples/rust/temporal-probe && cargo build` (needs `protoc`). Run: `bench/e9-probe.sh`
(needs `/opt/gobin/temporal`, built from a clone of temporalio/cli with `go build ./cmd/temporal`).
