'use strict';

import Alpine from 'alpinejs';
import api from './api';
import { EventSourceParserStream } from 'eventsource-parser/stream';

export function chat() {
    Alpine.data('chat', (
        model,
        adapters = [],
        assistant = null,
        conversation = null
    ) => ({
        adapters: [],
        adapter: null,

        conversation: null,
        assistant: assistant,
        history: null,
        assistants: null,
        file: null,
        prompt: null,
        isProcessing: false,
        parent: null,
        tree: null,
        quote: null,
        autoScroll: false,
        isDeleting: false,
        query: '',
        promptUpdated: false,
        call: null,

        options: {
            assistant: null,
            model: null,
            message: null,
        },

        time: 0,
        timer: null,

        showOptions(message = null, modal = 'options') {
            this.options.assistant = message ? message.assistant : this.assistant;
            this.options.message = message;
            this.options.model = message ? message.model : this.adapter.model;

            window.modal.open(modal);
        },

        applyOptions() {
            if (this.options.message) {
                this.regenerate(
                    this.options.message,
                    this.options.model,
                    this.options.assistant
                );
            } else {
                this.selectAssistant(this.options.assistant);
                this.adapter = this.adapters.find(
                    adapter => adapter.model === this.options.model
                );
            }

            this.options = {
                assistant: null,
                model: null,
                message: null,
            };

            window.modal.close();
        },

        init() {
            adapters.forEach(adapter => {
                if (adapter.is_available) {
                    adapter.models.forEach(model => {
                        if (model.is_available && model.is_enabled) {
                            this.adapters.push(model);
                        }
                    });
                }
            });

            this.adapter = this.adapters.find(adapter => adapter.model == model);

            if (!this.adapter && this.adapters.length > 0) {
                this.adapter = this.adapters[0];
            }

            if (conversation) {
                this.select(conversation);

                setTimeout(() => window.scroll({
                    behavior: 'smooth',
                    top: document.body.scrollHeight
                }), 500);
            }

            this.fetchHistory();
            this.getAssistants();

            window.addEventListener('scroll', () => {
                this.autoScroll = window.scrollY + window.innerHeight + 500 >= document.documentElement.scrollHeight;
            });

            window.addEventListener('mouseup', (e) => {
                this.$refs.quote.classList.add('hidden');
                this.$refs.quote.classList.remove('flex');
            });

            this.$watch('prompt', () => this.promptUpdated = true);

            // Parse query parameters and find the parameter 'q'
            let url = new URL(window.location.href);
            let query = url.searchParams.get('q');

            if (query) {
                this.prompt = query;
                this.submit();
            }
        },

        generateMap(msgId = null) {
            this.call = null;
            const map = new Map();

            this.conversation.messages.forEach(message => {
                map.set(message.id, message);
            });

            let tree = [];
            let parentId = null;

            while (true) {
                let node = {
                    index: 0,
                    children: []
                }

                this.conversation.messages.forEach(message => {
                    if (parentId === message.parent_id) {
                        node.children.push(message);
                    }
                });

                let ids = node.children.map(msg => msg.id);

                if (node.children.length > 0) {
                    if (msgId) {
                        let msg = map.get(msgId);

                        // Update indices to ensure the selected message is visible
                        while (msg) {
                            if (ids.indexOf(msg.id) >= 0) {
                                node.index = ids.indexOf(msg.id);
                                break;
                            }

                            if (msg.parent_id) {
                                msg = map.get(msg.parent_id);

                                continue;
                            }

                            break;
                        }
                    }

                    tree.push(node);
                    parentId = node.children[node.index].id;
                    continue;
                }

                break;
            }

            this.tree = tree;
        },

        fetchHistory() {
            api.get('/library/conversations', { limit: 5 })
                .then(response => response.json())
                .then(list => {
                    let data = list.data;
                    this.history = data.reverse();
                });
        },

        getAssistants(cursor = null) {
            let params = {
                limit: 250
            };

            if (cursor) {
                params.starting_after = cursor;
            }

            api.get('/assistants', params)
                .then(response => response.json())
                .then(list => {
                    if (!this.assistants) {
                        this.assistants = [];
                    }

                    this.assistants.push(...list.data);

                    if (list.data.length > 0 && list.data.length == params.limit) {
                        this.getAssistants(this.assistants[this.assistants.length - 1].id);
                    }
                });
        },

        stopProcessing() {
            this.isProcessing = false;
            clearInterval(this.timer);
            this.time = 0;
        },

        async submit() {
            if (this.isProcessing) {
                return;
            }

            this.isProcessing = true;
            clearInterval(this.timer);
            this.time = 0;
            this.timer = setInterval(() => this.time++, 1000);

            if (!this.conversation) {
                try {
                    await this.createConversation();
                } catch (error) {
                    this.stopProcessing();
                    return;
                }
            }

            let data = new FormData();
            data.append('content', this.prompt);
            data.append('model', this.adapter.model);

            if (this.assistant?.id) {
                data.append('assistant_id', this.assistant.id);
            }

            if (this.quote) {
                data.append('quote', this.quote);
            }

            let msgs = document.getElementsByClassName('message');
            if (msgs.length > 0) {
                let pid = msgs[msgs.length - 1].dataset.id;

                if (pid) {
                    data.append('parent_id', pid);
                }
            }

            if (
                // this.adapter?.supports_image && 
                this.file
            ) {
                data.append('file', this.file);
            }

            this.ask(data, this.assistant);
        },

        async ask(data, assistant) {
            try {
                let response = await api.post('/ai/conversations/' + this.conversation.id + '/messages', data);

                // Get the readable stream from the response body
                const stream = response.body
                    .pipeThrough(new TextDecoderStream())
                    .pipeThrough(new EventSourceParserStream());

                // Get the reader from the stream
                const reader = stream.getReader();

                // Temporary message
                let message = {
                    object: 'message',
                    id: 'temp',
                    model: null,
                    role: 'assistant',
                    content: '',
                    quote: null,
                    assistant: assistant,
                    parent_id: data.get('parent_id'),
                    children: []
                };
                let pushed = false;
                let lastMessage = null;

                window.scrollTo(0, document.body.scrollHeight);
                this.autoScroll = true;
                this.promptUpdated = false;

                this.file = null;

                while (true) {
                    if (this.autoScroll) {
                        window.scrollTo(0, document.body.scrollHeight);
                    }

                    const { value, done } = await reader.read();
                    if (done) {
                        this.stopProcessing();

                        // Remove messages with null id from the conversation
                        this.conversation.messages = this.conversation.messages
                            .filter(msg => msg.id !== 'temp');

                        this.generateMap(lastMessage.id);
                        break;
                    }

                    if (value.event == 'token') {
                        message.content += JSON.parse(value.data);

                        if (!pushed) {
                            this.conversation.messages.push(message);
                            pushed = true;
                        }

                        this.generateMap(message.id);
                        continue;
                    }

                    if (value.event == 'call') {
                        this.call = JSON.parse(value.data);

                        if (!this.promptUpdated) {
                            this.quote = null;
                            this.prompt = null;
                        }

                        continue;
                    }

                    if (value.event == 'message') {
                        let msg = JSON.parse(value.data);

                        if (!this.promptUpdated) {
                            this.quote = null;
                            this.prompt = null;
                        }

                        this.conversation.messages.push(msg);

                        if (msg.conversation) {
                            for (const [key, value] of Object.entries(msg.conversation)) {
                                if (key === 'messages') {
                                    continue;
                                }

                                this.conversation[key] = value;
                            }
                        }

                        this.conversation.title = msg.conversation.title;
                        this.conversation.cost = msg.conversation.cost;

                        if (msg.role === 'user') {
                            message.parent_id = msg.id;
                        }

                        this.generateMap(msg.id);
                        lastMessage = msg;
                        continue;
                    }

                    if (value.event == 'error') {
                        this.error(value.data);
                        break;
                    }
                }
            } catch (error) {
                this.error(error);
            }
        },

        error(msg) {
            this.stopProcessing();
            toast.error(msg);
            console.error(msg);

            // Remove messages with null id from the conversation
            this.conversation.messages = this.conversation.messages
                .filter(msg => msg.id !== 'temp');

            this.generateMap();
        },

        async createConversation() {
            let resp = await api.post('/ai/conversations');
            let conversation = resp.data;

            if (this.history === null) {
                this.history = [];
            }

            this.history.push(conversation);
            this.select(conversation);
        },

        select(conversation) {
            this.conversation = conversation;
            this.generateMap();

            let url = new URL(window.location.href);
            url.pathname = '/app/chat/' + conversation.id;
            url.search = '';
            window.history.pushState({}, '', url);

            // Find the first message in the last tree node
            if (this.tree.length === 0) {
                return;
            }

            let lastNode = this.tree[this.tree.length - 1];
            let lastMessage = lastNode.children[lastNode.index];

            // Set the adapter for the conversation based on the last message
            let adapter = this.adapters.find(adapter => adapter.model === lastMessage.model);
            if (adapter) {
                this.adapter = adapter;
            }

            // Set the assistant for the conversation based on the last message
            this.selectAssistant(lastMessage.assistant);
        },

        save(conversation) {
            api.post(`/library/conversations/${conversation.id}`, {
                title: conversation.title,
            }).then((resp) => {
                // Update the item in the history list
                if (this.history) {
                    let index = this.history.findIndex(item => item.id === resp.data.id);

                    if (index >= 0) {
                        this.history[index] = resp.data;
                    }
                }
            });
        },

        enter(e) {
            if (e.key === 'Enter' && !e.shiftKey && !this.isProcessing && this.prompt && this.prompt.trim() !== '') {
                e.preventDefault();
                this.submit();
            }
        },

        paste(e) {
            if (!this.adapter || this.adapter.file_types.length === 0) {
                return; // Allow default paste behavior if no file types are supported
            }

            const items = e.clipboardData.items;
            for (let i = 0; i < items.length; i++) {
                if (items[i].kind === 'file') {
                    const file = items[i].getAsFile();
                    if (file && this.adapter.file_types.includes(file.type)) {
                        this.file = file;
                        e.preventDefault(); // Prevent default paste only if we've found a supported file
                        break;
                    }
                }
            }
            // If no supported file is found, allow default paste behavior
        },

        copy(message) {
            navigator.clipboard.writeText(message.content)
                .then(() => {
                    toast.success('Copied to clipboard!');
                });
        },

        textSelect(e) {
            this.$refs.quote.classList.add('hidden');
            this.$refs.quote.classList.remove('flex');

            let selection = window.getSelection();

            if (selection.rangeCount <= 0) {
                return;
            }

            let range = selection.getRangeAt(0);
            let text = range.toString();

            if (text.trim() == '') {
                return;
            }

            e.stopPropagation();

            let startNode = range.startContainer;
            let startOffset = range.startOffset;

            let rect;
            if (startNode.nodeType === Node.TEXT_NODE) {
                // Create a temporary range to get the exact position of the start
                let tempRange = document.createRange();
                tempRange.setStart(startNode, startOffset);
                tempRange.setEnd(startNode, startOffset + 1); // Add one character to make the range visible
                rect = tempRange.getBoundingClientRect();
            } else if (startNode.nodeType === Node.ELEMENT_NODE) {
                // For element nodes, get the bounding rect directly
                rect = startNode.getBoundingClientRect();
            }

            // Adjust coordinates relative to the container (parent)
            let container = this.$refs.quote.parentElement;
            let containerRect = container.getBoundingClientRect();
            let x = rect.left - containerRect.left + container.scrollLeft;
            let y = rect.top - containerRect.top + container.scrollTop;

            this.$refs.quote.style.top = y + 'px';
            this.$refs.quote.style.left = x + 'px';

            this.$refs.quote.classList.add('flex');
            this.$refs.quote.classList.remove('hidden');

            this.$refs.quote.dataset.value = range.toString();

            return;

        },

        selectQuote() {
            this.quote = this.$refs.quote.dataset.value;
            this.$refs.quote.dataset.value = null;

            this.$refs.quote.classList.add('hidden');
            this.$refs.quote.classList.remove('flex');

            // Clear selection
            window.getSelection().removeAllRanges();
        },

        regenerate(message, model = null, assistant = null) {
            if (!message.parent_id) {
                return;
            }

            let parentMessage = this.conversation.messages.find(
                msg => msg.id === message.parent_id
            );

            if (!parentMessage) {
                return;
            }

            let data = new FormData();
            data.append('parent_id', parentMessage.id);
            data.append('model', model || message.model);

            if (assistant) {
                data.append('assistant_id', assistant.id);
            } else if (message.assistant) {
                data.append('assistant_id', message.assistant.id);
            }

            this.isProcessing = true;
            this.ask(data, message.assistant);
        },

        edit(message, content) {
            let data = new FormData();

            data.append('model', message.model);
            data.append('content', content);

            if (message.parent_id) {
                data.append('parent_id', message.parent_id);
            }

            if (message.assistant?.id) {
                data.append('assistant_id', message.assistant.id);
            }

            if (message.quote) {
                data.append('quote', message.quote);
            }

            this.isProcessing = true;
            this.ask(data, message.assistant);
        },

        remove(conversation) {
            this.isDeleting = true;

            api.delete(`/library/conversations/${conversation.id}`)
                .then(() => {
                    this.conversation = null;
                    window.modal.close();

                    toast.show("Conversation has been deleted successfully.", 'ti ti-trash');
                    this.isDeleting = false;

                    let url = new URL(window.location.href);
                    url.pathname = '/app/chat/';
                    window.history.pushState({}, '', url);

                    this.history.splice(this.history.indexOf(conversation), 1);
                })
                .catch(error => this.isDeleting = false);
        },

        doesAssistantMatch(assistant, query) {
            if (
                this.$store.workspace.subscription?.plan.config.assistants != null
                && !this.$store.workspace.subscription?.plan.config.assistants.includes(assistant.id)
            ) {
                return false;
            }

            query = query.trim().toLowerCase();

            if (!query) {
                return true;
            }

            if (assistant.name.toLowerCase().includes(query)) {
                return true;
            }

            if (assistant.expertise && assistant.expertise.toLowerCase().includes(query)) {
                return true;
            }

            if (assistant.description && assistant.description.toLowerCase().includes(query)) {
                return true;
            }

            return false;
        },

        selectAssistant(assistant) {
            this.assistant = assistant;
            window.modal.close();

            if (!this.conversation) {
                let url = new URL(window.location.href);
                url.pathname = '/app/chat/' + (assistant?.id || '');
                window.history.pushState({}, '', url);
            }
        },

        toolKey(item) {
            switch (item.object) {
                case 'image':
                    return 'imagine';

                default:
                    return null;
            }
        },
    }));
}