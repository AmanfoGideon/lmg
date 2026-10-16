// Small shared helpers.

// Trailing-edge debounce used by the table/search filters so typing does not
// trigger a re-render (or a network request) on every keystroke.
export function debounce(fn, wait = 200) {
  let timer;
  return (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), wait);
  };
}
