const EM_DASH = '—';
const MINUS = '−';

export function price(value, decimals) {
    if (value === null || value === undefined || value === '') {
        return { int: EM_DASH, frac: '' };
    }

    const fixed = Number(value).toFixed(decimals);
    const [whole, fraction] = fixed.split('.');

    return {
        int: Number(whole).toLocaleString('en-US'),
        frac: fraction === undefined ? '' : `.${fraction}`,
    };
}

export function signed(value, decimals) {
    if (value === null || value === undefined || value === '') {
        return EM_DASH;
    }

    const n = Number(value);
    const sign = n < 0 ? MINUS : '+';

    return `${sign}${Math.abs(n).toFixed(decimals)}`;
}

export function percent(value) {
    if (value === null || value === undefined || value === '') {
        return EM_DASH;
    }

    const n = Number(value);

    return `${n < 0 ? MINUS : '+'}${Math.abs(n).toFixed(2)}%`;
}

export function age(seconds) {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    const m = Math.floor(seconds / 60);
    const s = String(seconds % 60).padStart(2, '0');

    return `${m}m${s}s`;
}
