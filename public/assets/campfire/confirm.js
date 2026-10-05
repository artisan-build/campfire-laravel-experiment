document.addEventListener("submit", (event) => {
  const message = event.submitter?.dataset.confirm || event.target.dataset.confirm
  if (message && !globalThis.confirm(message)) event.preventDefault()
})
