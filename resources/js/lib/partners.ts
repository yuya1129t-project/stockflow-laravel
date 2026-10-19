/**
 * 取引先の画面で共通の型と関数（docs/spec.md §3・§5-4・§6）。入力検証はサーバー側（PartnerRequest）で行う
 */
import { addressLookup } from '@/routes/partners';

export type PartnerKind = 'customer' | 'supplier' | 'both';

/** 画面に渡す取引先（PartnerController::toProps）。郵便番号は 123-4567 の形、任意の項目は空なら '' */
export type Partner = {
    id: number;
    code: string;
    name: string;
    kind: PartnerKind;
    kindLabel: string;
    postalCode: string;
    address: string;
    phone: string;
    email: string;
    isActive: boolean;
};

export type KindOption = { value: PartnerKind; label: string };

/** 一覧の検索条件（PartnerListFilters::toArray） */
export type PartnerListFilters = {
    q: string;
    kind: PartnerKind | '';
    status: 'active' | 'all' | 'inactive';
    sort: 'code' | 'name' | 'kind';
    order: 'asc' | 'desc';
};

export const DEFAULT_FILTERS: PartnerListFilters = {
    q: '',
    kind: '',
    status: 'active',
    sort: 'code',
    order: 'asc',
};

/** 一覧の種別の絞り込み（「顧客」「仕入先」は両方の役割を持つ取引先も含む。PartnerKind::listFilter） */
export const KIND_FILTER_LABELS: Record<PartnerKind, string> = {
    customer: '顧客（顧客・仕入先を含む）',
    supplier: '仕入先（顧客・仕入先を含む）',
    both: '顧客・仕入先のみ',
};

export const STATUS_LABELS: Record<PartnerListFilters['status'], string> = {
    active: '有効のみ',
    all: '無効も表示',
    inactive: '無効のみ',
};

/** 検索条件を URL のクエリにする（既定値の項目は省く） */
export function toListQuery(
    filters: PartnerListFilters,
    page = 1,
): Record<string, string> {
    const query: Record<string, string> = {};
    if (filters.q) query.q = filters.q;
    if (filters.kind) query.kind = filters.kind;
    if (filters.status !== DEFAULT_FILTERS.status)
        query.status = filters.status;
    if (
        filters.sort !== DEFAULT_FILTERS.sort ||
        filters.order !== DEFAULT_FILTERS.order
    ) {
        query.sort = filters.sort;
        query.order = filters.order;
    }
    if (page > 1) query.page = String(page);
    return query;
}

/** 全角の数字を半角にし、ハイフンに見える文字を "-" に揃えて、ハイフンなしの 7 桁にする（App\Support\Partners\PostalCode と同じ）。形式が違えば null */
export function normalizePostalCode(value: string): string | null {
    const normalized = value
        .trim()
        .replace(/[０-９]/g, (c) =>
            String.fromCharCode(c.charCodeAt(0) - 0xfee0),
        )
        .replace(/[－‐‑–—−ー─]/g, '-');
    const match = /^(\d{3})-?(\d{4})$/.exec(normalized);
    return match ? `${match[1]}${match[2]}` : null;
}

export const INVALID_POSTAL_CODE_MESSAGE =
    '郵便番号は7桁の数字で入力してください（例 123-4567）。住所を手入力してください';

const LOOKUP_FAILED_MESSAGE =
    '住所の検索に失敗しました。住所を手入力してください';

export type AddressLookupResponse =
    | { ok: true; addresses: string[] }
    | { ok: false; message: string };

/** サーバー（GET /partners/address-lookup）に住所を問い合わせる。失敗はメッセージ付きの { ok: false } にする */
export async function fetchAddresses(
    postalCode: string,
): Promise<AddressLookupResponse> {
    try {
        const response = await fetch(
            addressLookup.url({ query: { postal_code: postalCode } }),
            {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            },
        );
        const body: unknown = await response.json().catch(() => null);
        const data = (body ?? {}) as {
            addresses?: unknown;
            message?: unknown;
        };
        if (
            response.ok &&
            Array.isArray(data.addresses) &&
            data.addresses.every((a) => typeof a === 'string')
        ) {
            return { ok: true, addresses: data.addresses as string[] };
        }
        return {
            ok: false,
            message:
                !response.ok && typeof data.message === 'string'
                    ? data.message
                    : LOOKUP_FAILED_MESSAGE,
        };
    } catch {
        return { ok: false, message: LOOKUP_FAILED_MESSAGE };
    }
}

export type LookupSnapshot = {
    requestId: number;
    postalCode: string;
    address: string;
};

/**
 * 住所検索の応答を使ってよいか。検索を始めた後に別の検索が始まった（識別子が変わった）、
 * または郵便番号・住所の入力が変わったときは、古い結果で入力を上書きしないよう捨てる
 */
export function isLookupResultCurrent(
    started: LookupSnapshot,
    current: LookupSnapshot,
): boolean {
    return (
        started.requestId === current.requestId &&
        normalizePostalCode(started.postalCode) ===
            normalizePostalCode(current.postalCode) &&
        started.address === current.address
    );
}
