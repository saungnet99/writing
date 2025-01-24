'use strict';

export class DropzoneElement extends HTMLElement {
    constructor() {
        super();
    }

    connectedCallback() {
        this.dataset.state = 'hidden';

        document.body.addEventListener('dragover', (e) => {
            e.preventDefault();
            this.dataset.state = 'visible';
        });

        document.body.addEventListener('dragleave', (e) => {
            e.preventDefault();
            this.dataset.state = 'hidden';
        });

        document.body.addEventListener('drop', (e) => {
            e.preventDefault();

            this.dataset.state = 'hidden';

            const fileInput = this.querySelector('input[type="file"]');
            if (fileInput && e.dataTransfer.files.length > 0) {
                const acceptedTypes = fileInput.accept ? fileInput.accept.split(',') : null;
                let selectedFile = null;

                for (let file of e.dataTransfer.files) {
                    if (!acceptedTypes || acceptedTypes.some(type => {
                        if (type.startsWith('.')) {
                            return file.name.toLowerCase().endsWith(type.toLowerCase());
                        } else {
                            return file.type.match(new RegExp(type.replace('*', '.*')));
                        }
                    })) {
                        selectedFile = file;
                        break;
                    }
                }

                if (selectedFile) {
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(selectedFile);
                    fileInput.files = dataTransfer.files;
                    fileInput.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
    }
}