export const PDF_MAX_SIZE_BYTES = 20 * 1024 * 1024;
export const PDF_MAX_SIZE_LABEL = '20 MB';
export const PDF_UPLOAD_HINT = `Formato permitido: PDF. Tamaño máximo por archivo: ${PDF_MAX_SIZE_LABEL}.`;
export const PDF_TYPE_ERROR = 'El archivo seleccionado debe ser un PDF válido.';
export const PDF_SIZE_ERROR = `Cada archivo PDF puede medir hasta ${PDF_MAX_SIZE_LABEL}.`;

export const isPdfFile = (file) => Boolean(
    file
    && /\.pdf$/i.test(String(file.name ?? ''))
    && (!file.type || file.type.toLowerCase() === 'application/pdf')
);

export const validatePdfFile = async (file) => {
    if (!file) {
        return PDF_TYPE_ERROR;
    }

    if (Number(file.size) > PDF_MAX_SIZE_BYTES) {
        return PDF_SIZE_ERROR;
    }

    if (!isPdfFile(file)) {
        return PDF_TYPE_ERROR;
    }

    try {
        const header = new TextDecoder().decode(await file.slice(0, 5).arrayBuffer());

        if (header !== '%PDF-') {
            return PDF_TYPE_ERROR;
        }
    } catch (error) {
        return PDF_TYPE_ERROR;
    }

    return '';
};
