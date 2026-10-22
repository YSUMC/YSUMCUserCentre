(() => {
  document.querySelectorAll('[data-edit-invitation]').forEach(button => {
    button.addEventListener('click', async () => {
      const id = button.dataset.editInvitation;
      const error = document.getElementById('invitation-error');
      error.hidden = true;
      button.disabled = true;
      try {
        const response = await fetch(error.dataset.endpoint, {
          method: 'PUT', credentials: 'same-origin',
          headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value},
          body: JSON.stringify({id, description: document.getElementById(`description-${id}`).value}),
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
          throw new Error(Object.values(result.errors || {}).flat().join('\n') || result.message || `HTTP ${response.status}`);
        }
        button.classList.replace('btn-info', 'btn-success');
        button.innerHTML = '<i class="fa fa-check"></i>';
      } catch (failure) {
        error.textContent = failure.message;
        error.hidden = false;
      } finally {
        button.disabled = false;
      }
    });
  });
})();
