(() => {
  // Keep the field outside React's managed tree and use the site's request hook.
  window.blessing?.event?.on('beforeFetch', request => {
    const url = new URL(request.url, window.location.href);
    if (request.method !== 'POST' || url.origin !== window.location.origin ||
        !url.pathname.endsWith('/auth/register')) return;
    const input = document.getElementById('invitation-code');
    if (!input) return;
    const code = input.value.trim();
    if (request.data instanceof FormData) request.data.set('invitationCode', code);
    else if (request.data && typeof request.data === 'object') request.data.invitationCode = code;
  });
})();
