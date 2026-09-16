/* E18-B / H35 fixture: N zero-length read(2) calls on /dev/zero, timed, ns/call printed to
 * stdout. This is the *baseline* arm -- a standalone binary, no interposer, real libc `read`.
 *
 * The *interposed* arm (once E18-I lands: the universal-park C shims + a thread-local gate,
 * crates/ignis/src/park/) needs to run this same loop from *inside* the ignis binary, since the
 * gate is only installed there -- a lookalike binary would measure nothing. TODO(E18-I): add an
 * `ignis --bench-read <iters>` hidden subcommand that runs this loop through FFI/inline and
 * prints the same ns/call number, so bench/e18-overhead.sh can diff the two builds' numbers
 * instead of printing interposed=n/a.
 */
#include <fcntl.h>
#include <stdio.h>
#include <stdlib.h>
#include <time.h>
#include <unistd.h>

int main(int argc, char **argv) {
    long iters = argc > 1 ? atol(argv[1]) : 10000000L;
    int fd = open("/dev/zero", O_RDONLY);
    if (fd < 0) {
        perror("open /dev/zero");
        return 1;
    }
    char buf[1];
    struct timespec t0, t1;
    clock_gettime(CLOCK_MONOTONIC, &t0);
    for (long i = 0; i < iters; i++) {
        ssize_t rc = read(fd, buf, 0);
        (void) rc;
    }
    clock_gettime(CLOCK_MONOTONIC, &t1);
    close(fd);
    double total_ns = (t1.tv_sec - t0.tv_sec) * 1e9 + (t1.tv_nsec - t0.tv_nsec);
    printf("%.2f\n", total_ns / (double) iters);
    return 0;
}
