'use strict';

import { markdownToHtml } from "../app/markdown";

export class MarkdownElement extends HTMLElement {
    static get observedAttributes() {
        return ['content'];
    }

    constructor() {
        super();

        // Create a container element
        this.container = document.createElement('div');
        this.appendChild(this.container);
    }

    connectedCallback() {
        this.updateContent();
    }

    attributeChangedCallback(name, oldValue, newValue) {
        newValue = markdownToHtml(newValue);

        if (oldValue !== newValue) {
            this.updateContent(newValue);
        }
    }

    updateContent(content) {
        const newContent = new DOMParser().parseFromString(content, 'text/html').body;
        this.updateElement(this.container, newContent);
    }

    updateElement(oldEl, newEl) {
        // Update attributes
        Array.from(newEl.attributes).forEach(attr => {
            if (oldEl.getAttribute(attr.name) !== attr.value) {
                oldEl.setAttribute(attr.name, attr.value);
            }
        });

        // Remove old attributes
        Array.from(oldEl.attributes).forEach(attr => {
            if (!newEl.hasAttribute(attr.name)) {
                oldEl.removeAttribute(attr.name);
            }
        });

        // Update child nodes
        const oldChildren = Array.from(oldEl.childNodes);
        const newChildren = Array.from(newEl.childNodes);

        const maxLength = Math.max(oldChildren.length, newChildren.length);

        for (let i = 0; i < maxLength; i++) {
            if (!oldChildren[i]) {
                oldEl.appendChild(newChildren[i].cloneNode(true));
            } else if (!newChildren[i]) {
                oldEl.removeChild(oldChildren[i]);
            } else if (
                oldChildren[i].nodeType === Node.TEXT_NODE
                && newChildren[i].nodeType === Node.TEXT_NODE
            ) {
                if (oldChildren[i].textContent !== newChildren[i].textContent) {
                    oldChildren[i].textContent = newChildren[i].textContent;
                }
            } else if (oldChildren[i].nodeName !== newChildren[i].nodeName) {
                oldEl.replaceChild(
                    newChildren[i].cloneNode(true),
                    oldChildren[i]
                );
            } else {
                this.updateElement(oldChildren[i], newChildren[i]);
            }
        }
    }
}


