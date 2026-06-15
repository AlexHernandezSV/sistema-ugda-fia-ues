const EMOJI_PATTERN = /[\p{Extended_Pictographic}\p{Emoji_Presentation}\uFE0F\u20E3]/gu;
const SCRIPT_BLOCK_PATTERN = /<\s*script\b[^>]*>[\s\S]*?<\s*\/\s*script\s*>/gi;
const EVENT_HANDLER_PATTERN = /\s+on[a-z]+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+)/gi;
const SCRIPT_PROTOCOL_PATTERN = /\b(?:javascript|vbscript)\s*:/gi;
const HTML_TAG_PATTERN = /<[^>]*>/g;
const ANGLE_BRACKET_PATTERN = /[<>]/g;

const TEXT_INPUT_TYPES = new Set([
    '',
    'email',
    'password',
    'search',
    'tel',
    'text',
    'url',
]);

export function sanitizeInputText(value) {
    if (typeof value !== 'string' || value === '') {
        return value;
    }

    return value
        .replace(SCRIPT_BLOCK_PATTERN, '')
        .replace(EVENT_HANDLER_PATTERN, '')
        .replace(SCRIPT_PROTOCOL_PATTERN, '')
        .replace(HTML_TAG_PATTERN, '')
        .replace(ANGLE_BRACKET_PATTERN, '')
        .replace(EMOJI_PATTERN, '');
}

function isSanitizableElement(element) {
    if (element instanceof HTMLTextAreaElement) {
        return true;
    }

    if (!(element instanceof HTMLInputElement)) {
        return false;
    }

    return TEXT_INPUT_TYPES.has(element.type);
}

function applySanitizedValue(element, value) {
    const start = element.selectionStart;
    const end = element.selectionEnd;
    element.value = value;

    if (typeof start === 'number' && typeof end === 'number') {
        const position = Math.min(start, value.length);
        element.setSelectionRange(position, position);
    }

    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function insertCleanText(element, text) {
    const cleanText = sanitizeInputText(text);

    if (cleanText === '') {
        return;
    }

    const start = element.selectionStart ?? element.value.length;
    const end = element.selectionEnd ?? element.value.length;
    const nextValue = `${element.value.slice(0, start)}${cleanText}${element.value.slice(end)}`;

    element.value = nextValue;
    const position = start + cleanText.length;
    element.setSelectionRange(position, position);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

export function installInputSanitizer() {
    document.addEventListener('beforeinput', (event) => {
        const target = event.target;

        if (!isSanitizableElement(target) || typeof event.data !== 'string') {
            return;
        }

        const cleanText = sanitizeInputText(event.data);

        if (cleanText === event.data) {
            return;
        }

        event.preventDefault();
        insertCleanText(target, cleanText);
    });

    document.addEventListener('paste', (event) => {
        const target = event.target;

        if (!isSanitizableElement(target)) {
            return;
        }

        const pastedText = event.clipboardData?.getData('text/plain') ?? '';
        const cleanText = sanitizeInputText(pastedText);

        if (cleanText === pastedText) {
            return;
        }

        event.preventDefault();
        insertCleanText(target, cleanText);
    });

    document.addEventListener('input', (event) => {
        const target = event.target;

        if (!isSanitizableElement(target)) {
            return;
        }

        const cleanValue = sanitizeInputText(target.value);

        if (cleanValue !== target.value) {
            applySanitizedValue(target, cleanValue);
        }
    });
}
