(function () {
    function toastMsg(key) {
        var config = window.ImageConstructorConfig || {};
        var msgs = config.toastMessages || {};
        return msgs[key] || key;
    }

    function getSourceFormat(file) {
        if (!file || !file.path) return 'png';
        var ext = file.path.split('.').pop().toLowerCase();
        if (ext === 'jpg' || ext === 'jpeg') return 'jpg';
        return 'png';
    }

    function setFormatDefault(sourceFormat) {
        var select = document.getElementById('ic-format');
        if (!select) return;
        for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].value === sourceFormat) {
                select.selectedIndex = i;
                break;
            }
        }
    }

    function getSelectedFormat() {
        var select = document.getElementById('ic-format');
        return select ? select.value : 'png';
    }

    document.addEventListener('alpine:init', () => {
        Alpine.store('ic', {
            editor: null,
            file: null,

            async open(file) {
                this.file = file;

                setFormatDefault(getSourceFormat(file));

                window.MoonShine?.ui?.toggleModal('image-constructor');
                await new Promise((r) => setTimeout(r, 300));

                const container = document.getElementById('ie-container');
                if (!container) {
                    return;
                }

                this._terminate();

                const FilerobotImageEditor = window.FilerobotImageEditor;
                if (!FilerobotImageEditor) {
                    window.MoonShine?.ui?.toast(toastMsg('editorNotLoaded'), 'error');
                    return;
                }

                const config = window.ImageConstructorConfig || {};
                const locale = config.locale || 'en';
                const translations = config.translations || {};

                const editorConfig = {
                    useBackendTranslations: false,
                    language: locale,
                    translations: translations,
                    source: file.url,
                    defaultSavedImageName: file.path ? file.path.split('/').pop() : undefined,
                    tabsIds: config.tabs || [],
                    defaultTabId: config.defaultTab || undefined,
                    defaultToolId: config.defaultTool || undefined,
                    theme: config.theme || {},
                    annotationsCommon: {
                        fill: '#ffffff',
                        stroke: '#000000',
                        strokeWidth: 0,
                        shadowOffsetX: 0,
                        shadowOffsetY: 0,
                        shadowBlur: 0,
                        shadowColor: '#000000',
                        shadowOpacity: 1,
                        opacity: 1,
                    },
                    Text: {
                        text: locale === 'ru' ? 'Текст...' : 'Text...',
                        fontFamily: 'Arial',
                        fontSize: 32,
                        fill: '#ffffff',
                        fontStyle: 'bold',
                        shadowOffsetX: 1,
                        shadowOffsetY: 1,
                        shadowBlur: 4,
                        shadowColor: '#000000',
                        shadowOpacity: 0.6,
                    },
                    Crop: {
                        presetsItems: [
                            { titleKey: 'cropPresetFree', descriptionKey: 'Free', ratio: null },
                            { titleKey: 'cropPresetSquare', descriptionKey: '1:1', ratio: 1 },
                            { titleKey: 'landscape4:3', descriptionKey: '4:3', ratio: 4 / 3 },
                            { titleKey: 'landscape16:9', descriptionKey: '16:9', ratio: 16 / 9 },
                            { titleKey: 'portrait3:4', descriptionKey: '3:4', ratio: 3 / 4 },
                            { titleKey: 'portrait9:16', descriptionKey: '9:16', ratio: 9 / 16 },
                        ],
                    },
                    closeAfterSave: false,
                    avoidChangesNotSavedAlertOnLeave: true,
                    onBeforeSave: (isCancel) => {
                        return !isCancel;
                    },
                    onSave: (editedImageObject) => {
                        this._saveToServer(editedImageObject, getSelectedFormat());
                    },
                };

                if (config.watermarkGallery && config.watermarkGallery.length > 0) {
                    editorConfig.Watermark = {
                        gallery: config.watermarkGallery,
                        imageScalingRatio: 0.33,
                    };
                }

                this.editor = new FilerobotImageEditor(container, editorConfig);
                this.editor.render({
                    onClose: () => {
                        this.close();
                    },
                });
            },

            async _saveToServer(editedImageObject, targetFormat) {
                const config = window.ImageConstructorConfig || {};

                try {
                    const base64 = editedImageObject.imageBase64;
                    const fullName = editedImageObject.fullName || 'edited.png';

                    const res = await fetch(base64);
                    const blob = await res.blob();

                    const formData = new FormData();
                    formData.append('source_path', this.file?.path || '');
                    formData.append('image', blob, fullName);
                    formData.append('target_format', targetFormat || 'png');

                    const csrfToken =
                        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                    const response = await fetch(config.saveUrl || '', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    const data = await response.json();

                    if (data.status) {
                        window.MoonShine?.ui?.toast(toastMsg('saved'), 'success');
                        window.dispatchEvent(new CustomEvent('mm:refresh'));
                        this.close();
                    } else {
                        window.MoonShine?.ui?.toast(data.message || toastMsg('saveFailed'), 'error');
                    }
                } catch (e) {
                    window.MoonShine?.ui?.toast(e.message || toastMsg('saveFailed'), 'error');
                }
            },

            close() {
                this._terminate();
                this.file = null;

                const container = document.getElementById('ie-container');
                if (container) {
                    container.innerHTML = '';
                }

                window.MoonShine?.ui?.toggleModal('image-constructor');
            },

            _terminate() {
                if (this.editor) {
                    try {
                        this.editor.terminate();
                    } catch (e) {}
                    this.editor = null;
                }
            },
        });
    });
})();
