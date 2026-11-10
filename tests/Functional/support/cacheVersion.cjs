// The cache version (?v=YYYYMMDD-…) of a script in templates/pos.html.twig, and
// whether it is at least a given date: a later change may bump it again, so
// tests check "not older than", never one exact version.
function cacheVersionDate(page, file) {
    const escaped = file.replace(/[.*+?^${}()|[\]\\/]/g, '\\$&');
    const match = new RegExp(`/assets/js/${escaped}\\?v=(\\d{8})-`).exec(page);
    return match ? match[1] : null;
}

function hasCacheVersionSince(page, file, minDate) {
    const date = cacheVersionDate(page, file);
    return date !== null && date >= minDate;
}

module.exports = { cacheVersionDate, hasCacheVersionSince };
