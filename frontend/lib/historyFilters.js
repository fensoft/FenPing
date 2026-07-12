function historyTimestamp(row, key, dateKey) {
  const seconds = Number(row?.[key]);
  if (Number.isFinite(seconds) && seconds > 0) return seconds;

  const text = String(row?.[dateKey] || '').trim();
  if (text === '') return NaN;
  const plain = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/;
  const milliseconds = Date.parse(plain.test(text) ? `${text.replace(' ', 'T')}Z` : text);
  return Number.isNaN(milliseconds) ? NaN : Math.floor(milliseconds / 1000);
}

export function filterHistoryRows(rows, hours, nowSeconds = Math.floor(Date.now() / 1000)) {
  const cutoff = nowSeconds - Math.max(1, Number(hours) || 24) * 60 * 60;

  return (rows || []).flatMap((row) => {
    const begin = historyTimestamp(row, 'begin', 'date_begin');
    const end = historyTimestamp(row, 'end', 'date_end');
    if (!Number.isFinite(begin) || !Number.isFinite(end) || end <= cutoff || begin > nowSeconds) return [];

    const clippedBegin = Math.max(begin, cutoff);
    const clippedEnd = Math.min(end, nowSeconds);
    if (clippedEnd <= clippedBegin) return [];

    if (clippedBegin === begin && clippedEnd === end) return [row];
    return [{
      ...row,
      begin: clippedBegin,
      end: clippedEnd,
      date_begin: new Date(clippedBegin * 1000).toISOString(),
      date_end: new Date(clippedEnd * 1000).toISOString(),
      duration: clippedEnd - clippedBegin
    }];
  });
}
