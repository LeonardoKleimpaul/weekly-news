(() => {
    const text = document.querySelector('[data-submission-text]');
    const photo = document.querySelector('[data-submission-photo]');
    const previewText = document.querySelector('[data-preview-text]');
    const previewPhoto = document.querySelector('[data-preview-photo]');
    const placeholder = document.querySelector('[data-photo-placeholder]');
    if (!text || !photo || !previewText || !previewPhoto || !placeholder) return;

    const originalPhoto = previewPhoto.getAttribute('src');
    let objectUrl = null;

    text.addEventListener('input', () => {
        const value = text.value.trim();
        previewText.textContent = value || 'O texto da sua história aparece aqui enquanto você escreve.';
        previewText.classList.toggle('muted', !value);
    });

    photo.addEventListener('change', () => {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = null;
        const file = photo.files[0];
        if (file && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            objectUrl = URL.createObjectURL(file);
        }
        const source = objectUrl || originalPhoto;
        if (source) previewPhoto.src = source;
        else previewPhoto.removeAttribute('src');
        previewPhoto.hidden = !source;
        placeholder.hidden = !!source;
    });
})();
