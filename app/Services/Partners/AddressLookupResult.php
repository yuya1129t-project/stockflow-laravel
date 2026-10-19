<?php

namespace App\Services\Partners;

/** 住所検索の結果。成功なら住所の候補（1 つの郵便番号に複数の町域があることがある）、失敗なら理由 */
final readonly class AddressLookupResult
{
    /**
     * @param  list<string>  $addresses  都道府県＋市区町村＋町域
     */
    private function __construct(
        public ?string $postalCode,
        public array $addresses,
        public ?AddressLookupFailure $failure,
    ) {}

    /** @param  non-empty-list<string>  $addresses */
    public static function found(string $postalCode, array $addresses): self
    {
        return new self($postalCode, $addresses, null);
    }

    public static function failed(AddressLookupFailure $failure, ?string $postalCode = null): self
    {
        return new self($postalCode, [], $failure);
    }

    public function ok(): bool
    {
        return $this->failure === null;
    }
}
