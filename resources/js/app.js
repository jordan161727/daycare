import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.data('childrenImport', () => ({
    loading: false,
    filename: '',
    filesize: '',
    drag: false,
    error: '',

    selectFile(event) {
        this.setFile(event.target.files[0]);
    },

    handleDrop(event) {
        const file = event.dataTransfer.files[0];

        if (file) {
            this.$refs.file.files = event.dataTransfer.files;
            this.setFile(file);
        }

        this.drag = false;
    },

    setFile(file) {
        if (!file) return;

        const extension = file.name.split('.').pop().toLowerCase();

        if (!['xlsx', 'xls', 'csv'].includes(extension)) {
            this.clearFile();
            this.error = 'Only Excel (.xlsx, .xls) or CSV files are allowed.';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            this.clearFile();
            this.error = 'The file must not be larger than 5 MB.';
            return;
        }

        this.error = '';
        this.filename = file.name;
        this.filesize = `${(file.size / 1024).toFixed(1)} KB`;
    },

    clearFile() {
        this.$refs.file.value = '';
        this.filename = '';
        this.filesize = '';
    },

    submitForm(event) {
        if (!this.filename || this.error) {
            event.preventDefault();
            this.error = this.error || 'Select a file before importing.';
            return;
        }

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

Alpine.start();
