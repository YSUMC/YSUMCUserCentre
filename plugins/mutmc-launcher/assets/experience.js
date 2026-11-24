(() => {
  const form = document.getElementById('launcher-experience');
  if (!form) return;
  let sequence = Date.now();
  form.addEventListener('click', (event) => {
    const add = event.target.closest('[data-add]');
    if (add) {
      const section = add.dataset.add;
      const template = form.querySelector(`template[data-template="${section}"]`);
      const fragment = template.content.cloneNode(true);
      const index = String(sequence++);
      fragment.querySelectorAll('[name]').forEach((element) => {
        element.name = element.name.replace('__INDEX__', index);
        if (element.value === '__ID__') element.value = crypto.randomUUID();
      });
      form.querySelector(`[data-rows="${section}"]`).appendChild(fragment);
    }
    const remove = event.target.closest('[data-remove]');
    if (remove) remove.closest('[data-row]').remove();
  });
})();
