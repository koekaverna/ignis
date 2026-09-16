/* H36 (ADR-0020 acceptance 5, research 27 §"locklib.c"): the smallest library with the hazard
 * shape — take a non-recursive mutex, make a blocking syscall, release. Under `park` two fibers on
 * ONE OS thread must deadlock (fiber A parks holding the mutex, fiber B waits for a mutex whose
 * owner will not run again until B yields); under `block` the second fiber never starts until the
 * first returns, so there is nothing to deadlock on.
 *
 * Build: cc -shared -fPIC -o /tmp/e18/liblocklib.so bench/e18/locklib.c -lpthread
 * Driven by `ignis_locklib_call()` (registered only when IGNIS_LOCKLIB names this .so).
 */
#include <pthread.h>
#include <unistd.h>

static pthread_mutex_t g_lock = PTHREAD_MUTEX_INITIALIZER;

/* Returns what read(2) returned, or -2 if the mutex could not be taken without blocking
 * (trylock mode, used to report the deadlock instead of hanging the harness for ever). */
long locklib_call(int fd, char *buf, unsigned long len, int trylock) {
    if (trylock) {
        if (pthread_mutex_trylock(&g_lock) != 0) {
            return -2;
        }
    } else {
        pthread_mutex_lock(&g_lock);
    }
    ssize_t n = read(fd, buf, len);   /* the interposed symbol */
    pthread_mutex_unlock(&g_lock);
    return (long) n;
}
