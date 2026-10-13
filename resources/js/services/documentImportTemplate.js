import axios from 'axios';

const TEMPLATE_PATH = '/api/transfers/document-import-template';
const MAX_GET_QUERY_LENGTH = 4000;

const downloadBlob = (blob) => {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = 'plantilla-importacion-documentos.xlsx';
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
};

export const downloadDocumentImportTemplate = async ({
    series = [],
    yearStart = '',
    yearEnd = '',
    context = '',
} = {}) => {
    const seriesLabels = Array.isArray(series)
        ? series.map((label) => String(label ?? '').trim()).filter(Boolean)
        : [];
    const params = new URLSearchParams();

    if (seriesLabels.length > 0) {
        params.set('series', seriesLabels.join('; '));
    }

    if (yearStart) {
        params.set('year_start', yearStart);
    }

    if (yearEnd) {
        params.set('year_end', yearEnd);
    }

    if (context) {
        params.set('context', context);
    }

    const query = params.toString();

    if (query.length <= MAX_GET_QUERY_LENGTH) {
        window.location.href = `${TEMPLATE_PATH}${query ? `?${query}` : ''}`;
        return;
    }

    const payload = { series: seriesLabels };

    if (yearStart) {
        payload.year_start = yearStart;
    }

    if (yearEnd) {
        payload.year_end = yearEnd;
    }

    if (context) {
        payload.context = context;
    }

    const { data } = await axios.post(TEMPLATE_PATH, payload, {
        responseType: 'blob',
    });

    downloadBlob(data);
};
