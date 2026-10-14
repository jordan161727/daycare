import './bootstrap';
import Alpine from 'alpinejs';
import 'flatpickr/dist/flatpickr.min.css';
import { watchDateInputs } from './dates';

window.Alpine = Alpine;

// A page the back button brings back from the browser's back/forward cache
// is the page as it was, not as it is: a roster shown before a child was
// corrected still shows them uncorrected. The server already asks the
// browser not to keep pages; this covers the browsers that keep them anyway.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) window.location.reload();
});

Alpine.data('childrenImport', () => ({
    loading: false,
    files: [],
    drag: false,
    error: '',

    // Choosing again adds to what is there rather than replacing it, so a
    // file per room can be picked one at a time as well as all at once.
    selectFile(event) {
        this.addFiles([...event.target.files]);
    },

    handleDrop(event) {
        this.addFiles([...event.dataTransfer.files]);
        this.drag = false;
    },

    addFiles(incoming) {
        this.error = '';

        for (const file of incoming) {
            const extension = file.name.split('.').pop().toLowerCase();

            if (!['xlsx', 'xls', 'csv'].includes(extension)) {
                this.error = `${file.name}: only Excel (.xlsx, .xls) or CSV files are allowed.`;
                continue;
            }

            if (file.size > 5 * 1024 * 1024) {
                this.error = `${file.name}: the file must not be larger than 5 MB.`;
                continue;
            }

            if (this.files.some(f => f.name === file.name && f.size === file.size)) continue;

            if (this.files.length >= 20) {
                this.error = 'Up to 20 files at a time.';
                break;
            }

            this.files.push(file);
        }

        this.syncInput();
    },

    removeFile(index) {
        this.files.splice(index, 1);
        this.error = '';
        this.syncInput();
    },

    clearFiles() {
        this.files = [];
        this.error = '';
        this.syncInput();
    },

    // The input is what the form posts, so it is rebuilt from the list every
    // time the list changes — a DataTransfer is the only way to set it.
    syncInput() {
        try {
            const transfer = new DataTransfer();
            this.files.forEach(file => transfer.items.add(file));
            this.$refs.file.files = transfer.files;
        } catch {
            if (!this.files.length) this.$refs.file.value = '';
        }
    },

    size(file) {
        return `${(file.size / 1024).toFixed(1)} KB`;
    },

    submitForm(event) {
        if (!this.files.length || this.error) {
            event.preventDefault();
            this.error = this.error || 'Select a file before importing.';
            return;
        }

        this.loading = true;
    },
}));

Alpine.data('documentImport', () => ({
    loading: false,
    files: [],
    preview: '',
    drag: false,
    error: '',
    selectFile(event) { this.addFiles([...event.target.files]); },
    handleDrop(event) { this.addFiles([...event.dataTransfer.files]); this.drag = false; },
    // Choosing again adds to the pile rather than replacing it, so a drawer
    // of forms can be picked a few at a time.
    addFiles(incoming) {
        this.error = '';
        const allowed = ['pdf', 'png', 'jpg', 'jpeg'];
        for (const file of incoming) {
            const extension = file.name.split('.').pop().toLowerCase();
            if (!allowed.includes(extension)) { this.error = `${file.name}: only PDF, PNG, and JPG files are allowed.`; continue; }
            if (file.size > 10 * 1024 * 1024) { this.error = `${file.name}: the file must not be larger than 10 MB.`; continue; }
            if (this.files.some(f => f.name === file.name && f.size === file.size)) continue;
            if (this.files.length >= 20) { this.error = 'Up to 20 forms at a time.'; break; }
            this.files.push(file);
        }
        this.refreshPreview();
        this.syncInput();
    },
    removeFile(index) { this.files.splice(index, 1); this.error = ''; this.refreshPreview(); this.syncInput(); },
    // The preview is the first image in the pile; a PDF has none to show.
    refreshPreview() {
        if (this.preview) URL.revokeObjectURL(this.preview);
        const image = this.files.find(file => file.type.startsWith('image/'));
        this.preview = image ? URL.createObjectURL(image) : '';
    },
    // The input is what the form posts, so it is rebuilt from the list every
    // time the list changes — a DataTransfer is the only way to set it.
    syncInput() {
        try {
            const transfer = new DataTransfer();
            this.files.forEach(file => transfer.items.add(file));
            this.$refs.file.files = transfer.files;
        } catch { if (!this.files.length) this.$refs.file.value = ''; }
    },
    size(file) { return `${(file.size / 1024 / 1024).toFixed(2)} MB`; },
    totalSize() { return `${(this.files.reduce((sum, file) => sum + file.size, 0) / 1024 / 1024).toFixed(2)} MB`; },
    submitForm(event) {
        if (!this.files.length || this.error) { event.preventDefault(); this.error = this.error || 'Choose an enrollment form first.'; return; }
        this.loading = true;
    },
}));


Alpine.data('appShell', () => ({
    mobileOpen: false,
    collapsed: false,
    dark: localStorage.getItem('daycare-dark') === 'true',
    init() {
        this.$watch('dark', value => {
            document.documentElement.classList.toggle('dark', value);
            localStorage.setItem('daycare-dark', value);
        });
        document.documentElement.classList.toggle('dark', this.dark);
    },
}));


// Shows the picked photo straight away, so the profile page confirms the choice
// before the upload happens.
Alpine.data('avatarPicker', () => ({
    preview: '',
    filename: '',
    select(event) {
        const file = event.target.files[0];
        if (!file) return this.clear();
        if (this.preview) URL.revokeObjectURL(this.preview);
        this.filename = file.name;
        this.preview = URL.createObjectURL(file);
    },
    clear() {
        if (this.preview) URL.revokeObjectURL(this.preview);
        this.$refs.photo.value = '';
        this.preview = '';
        this.filename = '';
    },
}));

Alpine.start();

// Every date field reads mm/dd/yyyy whatever the computer's language — see dates.js.
watchDateInputs();
