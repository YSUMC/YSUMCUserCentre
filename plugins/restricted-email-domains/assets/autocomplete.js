(() => {
  function initialize() {
    const source = document.getElementById('allowed-email-domains');
    if (!source) return;
    let domains;
    try { domains = JSON.parse(source.textContent || '[]'); } catch { return; }
    if (!Array.isArray(domains) || !domains.length) return;
    if (window.blessing?.event?.on) {
      blessing.event.on('emailDomainsSuggestion', suggestions => {
        suggestions.clear();
        domains.forEach(domain => suggestions.add(domain));
      });
    }
    document.querySelectorAll('input[type="email"]').forEach((input, index) => {
      const list = document.createElement('datalist');
      list.id = `allowed-email-domains-${index}`;
      input.setAttribute('list', list.id);
      input.after(list);
      input.addEventListener('input', () => {
        const prefix = input.value.split('@')[0];
        list.replaceChildren();
        if (!prefix) return;
        for (const domain of domains) {
          const option = document.createElement('option');
          option.value = `${prefix}@${domain}`;
          list.append(option);
        }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
