/**
 * A fake clock for the vm-sandbox harnesses: setTimeout/setInterval run only when
 * advance(ms) moves the time past them, and Date.now() follows the fake time.
 * Each timer callback is followed by a macrotask, so awaited API calls settle.
 */
const flush = () => new Promise((resolve) => setImmediate(resolve));

function makeClock(start = Date.parse('2026-11-03T17:00:00+01:00')) {
    const timers = [];
    let nextId = 0;
    const clock = {
        now: start,
        setTimeout(fn, ms) {
            const timer = { id: ++nextId, at: clock.now + (Number(ms) || 0), fn };
            timers.push(timer);
            return timer.id;
        },
        clearTimeout(id) {
            const index = timers.findIndex((t) => t.id === id);
            if (index >= 0) timers.splice(index, 1);
        },
        setInterval(fn, ms) {
            const timer = { id: ++nextId, at: clock.now + ms, fn, every: ms };
            timers.push(timer);
            return timer.id;
        },
        clearInterval(id) { clock.clearTimeout(id); },
        pending: () => timers.length,
        async advance(ms) {
            const end = clock.now + ms;
            await flush();
            for (;;) {
                timers.sort((a, b) => a.at - b.at || a.id - b.id);
                const timer = timers[0];
                if (!timer || timer.at > end) break;
                clock.now = timer.at;
                if (timer.every) timer.at += timer.every; else timers.shift();
                timer.fn();
                await flush();
            }
            clock.now = end;
            await flush();
        },
    };
    class FakeDate extends Date {
        constructor(...args) { if (args.length === 0) super(clock.now); else super(...args); }
        static now() { return clock.now; }
    }
    clock.Date = FakeDate;
    return clock;
}

/** The globals a store or component needs in a vm sandbox, with the fake clock. */
function sandboxGlobals(clock) {
    return {
        setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout,
        setInterval: clock.setInterval, clearInterval: clock.clearInterval,
        Date: clock.Date, Number, String, Math, JSON, Promise, Error, Array, Object, Boolean, RegExp,
        parseInt, parseFloat, isNaN, encodeURIComponent, URLSearchParams, console,
    };
}

module.exports = { makeClock, sandboxGlobals, flush };
