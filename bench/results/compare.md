
### 2026-09-15T22:45:39Z wrk -t2 -c64 -d10s, 4 vCPU, Intel(R) Xeon(R) Processor @ 2.80GHz
| server | req/s | p50 | p99 | errors |
|---|---|---|---|---|
| ignis (1 PHP thread + 2 tokio) | 134253.55 | 423.00us | 1.07ms | |

### 2026-09-15T22:53:07Z wrk -t2 -c64 -d10s, 4 vCPU, Intel(R) Xeon(R) Processor @ 2.80GHz
| server | req/s | p50 | p99 | errors |
|---|---|---|---|---|
| ignis (1 PHP thread + 2 tokio) | 128072.28 | 453.00us | 1.11ms | |
| php-fpm (pm.max_children=1) + nginx | 9852.52 | 6.30ms | 8.68ms | |
| php-fpm (pm.max_children=4) + nginx | 11106.25 | 5.69ms | 7.41ms | |

### 2026-09-15T22:55:26Z wrk -t2 -c64 -d10s, 4 vCPU, Intel(R) Xeon(R) Processor @ 2.80GHz
| server | req/s | p50 | p99 | errors |
|---|---|---|---|---|
| frankenphp worker (num=1, num_threads=2) | 27627.43 | 2.12ms | 6.42ms | |
| frankenphp worker (num=4, num_threads=5) | 16582.78 | 3.77ms | 10.35ms | |
