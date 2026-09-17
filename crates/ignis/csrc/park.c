/* E18 (ADR-0020): the blocking libc calls, exported from the ignis binary itself.
 *
 * Because the executable is first in the process's symbol lookup scope, every shared library
 * loaded after it — libphp, libcurl, libpq, libssl — binds these names to us instead of glibc
 * (research 28: a `poll` inside curl_easy_perform reached the executable 8 times). Each function
 * is one line: capture the caller's return address, which stable Rust cannot do and which the
 * per-library policy needs, and hand everything to the Rust handler in src/php/park.rs.
 *
 * The Rust side never calls these wrappers back for its own forwarding — it uses raw syscall(2) —
 * so a handler cannot re-enter itself through the very symbol it interposes.
 *
 * All of these live in ONE object file on purpose: the linker pulls an archive member as a whole,
 * and Rust std already references read/write/poll/connect, so every symbol here reaches the
 * final link even when nothing in the executable names it. build.rs adds -Wl,-u,NAME and
 * -Wl,--export-dynamic-symbol=NAME per symbol as well.
 */
#define _GNU_SOURCE
#include <poll.h>
#include <signal.h>
#include <stddef.h>
#include <sys/file.h>
#include <sys/select.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <sys/uio.h>
#include <time.h>
#include <unistd.h>

ssize_t ignis_park_read(const void *ret, int fd, void *buf, size_t n);
ssize_t ignis_park_write(const void *ret, int fd, const void *buf, size_t n);
ssize_t ignis_park_recv(const void *ret, int fd, void *buf, size_t n, int flags);
ssize_t ignis_park_send(const void *ret, int fd, const void *buf, size_t n, int flags);
ssize_t ignis_park_recvfrom(const void *ret, int fd, void *buf, size_t n, int flags, struct sockaddr *addr, socklen_t *alen);
ssize_t ignis_park_sendto(const void *ret, int fd, const void *buf, size_t n, int flags, const struct sockaddr *addr, socklen_t alen);
int ignis_park_poll(const void *ret, struct pollfd *fds, nfds_t n, int timeout);
int ignis_park_connect(const void *ret, int fd, const struct sockaddr *addr, socklen_t alen);
int ignis_park_nanosleep(const void *ret, const struct timespec *req, struct timespec *rem);
int ignis_park_usleep(const void *ret, useconds_t us);
unsigned ignis_park_sleep(const void *ret, unsigned s);
/* stage 2 (ADR-0037 cycle 2) */
int ignis_park_accept4(const void *ret, int fd, struct sockaddr *addr, socklen_t *alen, int flags);
int ignis_park_select(const void *ret, int n, fd_set *r, fd_set *w, fd_set *e, struct timeval *tv);
int ignis_park_ppoll(const void *ret, struct pollfd *fds, nfds_t n, const struct timespec *ts, const sigset_t *mask);
ssize_t ignis_park_recvmsg(const void *ret, int fd, struct msghdr *msg, int flags);
ssize_t ignis_park_sendmsg(const void *ret, int fd, const struct msghdr *msg, int flags);
ssize_t ignis_park_readv(const void *ret, int fd, const struct iovec *iov, int cnt);
int ignis_park_flock(const void *ret, int fd, int operation);
ssize_t ignis_park_writev(const void *ret, int fd, const struct iovec *iov, int cnt);

ssize_t read(int fd, void *buf, size_t n) { return ignis_park_read(__builtin_return_address(0), fd, buf, n); }
ssize_t write(int fd, const void *buf, size_t n) { return ignis_park_write(__builtin_return_address(0), fd, buf, n); }
ssize_t recv(int fd, void *buf, size_t n, int flags) { return ignis_park_recv(__builtin_return_address(0), fd, buf, n, flags); }
ssize_t send(int fd, const void *buf, size_t n, int flags) { return ignis_park_send(__builtin_return_address(0), fd, buf, n, flags); }
ssize_t recvfrom(int fd, void *buf, size_t n, int flags, struct sockaddr *addr, socklen_t *alen) { return ignis_park_recvfrom(__builtin_return_address(0), fd, buf, n, flags, addr, alen); }
ssize_t sendto(int fd, const void *buf, size_t n, int flags, const struct sockaddr *addr, socklen_t alen) { return ignis_park_sendto(__builtin_return_address(0), fd, buf, n, flags, addr, alen); }
int poll(struct pollfd *fds, nfds_t n, int timeout) { return ignis_park_poll(__builtin_return_address(0), fds, n, timeout); }
int connect(int fd, const struct sockaddr *addr, socklen_t alen) { return ignis_park_connect(__builtin_return_address(0), fd, addr, alen); }
int nanosleep(const struct timespec *req, struct timespec *rem) { return ignis_park_nanosleep(__builtin_return_address(0), req, rem); }
int usleep(useconds_t us) { return ignis_park_usleep(__builtin_return_address(0), us); }
unsigned sleep(unsigned s) { return ignis_park_sleep(__builtin_return_address(0), s); }
int accept(int fd, struct sockaddr *addr, socklen_t *alen) { return ignis_park_accept4(__builtin_return_address(0), fd, addr, alen, 0); }
int accept4(int fd, struct sockaddr *addr, socklen_t *alen, int flags) { return ignis_park_accept4(__builtin_return_address(0), fd, addr, alen, flags); }
int select(int n, fd_set *r, fd_set *w, fd_set *e, struct timeval *tv) { return ignis_park_select(__builtin_return_address(0), n, r, w, e, tv); }
int ppoll(struct pollfd *fds, nfds_t n, const struct timespec *ts, const sigset_t *mask) { return ignis_park_ppoll(__builtin_return_address(0), fds, n, ts, mask); }
/* S1-FLOCK: a regular file cannot be parked on, so a blocking LOCK_EX inside a fiber took the whole
 * OS thread down (V-58). NOT fcntl — opcache's zend_shared_alloc_lock uses that one. */
int flock(int fd, int operation) { return ignis_park_flock(__builtin_return_address(0), fd, operation); }
/* _FORTIFY_SOURCE builds call poll through this; same policy row as `poll`. */
int __poll_chk(struct pollfd *fds, nfds_t n, int timeout, size_t fdslen) { (void)fdslen; return ignis_park_poll(__builtin_return_address(0), fds, n, timeout); }
ssize_t recvmsg(int fd, struct msghdr *msg, int flags) { return ignis_park_recvmsg(__builtin_return_address(0), fd, msg, flags); }
ssize_t sendmsg(int fd, const struct msghdr *msg, int flags) { return ignis_park_sendmsg(__builtin_return_address(0), fd, msg, flags); }
ssize_t readv(int fd, const struct iovec *iov, int cnt) { return ignis_park_readv(__builtin_return_address(0), fd, iov, cnt); }
ssize_t writev(int fd, const struct iovec *iov, int cnt) { return ignis_park_writev(__builtin_return_address(0), fd, iov, cnt); }
