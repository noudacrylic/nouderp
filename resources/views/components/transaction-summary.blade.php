@props([
    'subtotal' => 0,
    'discountItem' => 0,
    'globalDiscountValue' => 0,
    'globalDiscountType' => 'nominal',
    'ppnPercent' => 0,
    'shippingCost' => 0,
    'additionalFee' => 0,
    'advancePaid' => null,
    'remaining' => null,
    'notes' => '',
    'mode' => 'display'
])

<div class="border border-gray-100 rounded-xl p-6 bg-gray-50/50 shadow-sm space-y-4 summary-section">
    <div class="space-y-3 text-sm">
                <!-- Subtotal -->
                <div class="flex justify-between text-gray-600">
                    <span>Subtotal</span>
                    <span id="subtotal_display" class="font-medium text-gray-900 summary-subtotal">{{ format_rupiah($subtotal) }}</span>
                    <input type="hidden" name="subtotal" id="subtotal_input" value="{{ $subtotal }}">
                    <input type="hidden" name="subtotal_product" id="subtotal_product_input" value="{{ $subtotal }}">
                </div>

                <!-- Global Discount -->
                <div class="flex justify-between items-center text-gray-600">
                    <span>Discount</span>
                    <div class="flex gap-2">
                        @php
                            $gDiscType = $globalDiscountType ?? 'percent';
                            $gDiscVal = $globalDiscountValue ?? 0;
                        @endphp
                        <select name="global_discount_type" id="globalDiscountType"
                            class="border border-gray-300 rounded px-2 py-1 w-20 bg-white focus:ring-blue-500 focus:border-blue-500">
                            <option value="percent" {{ $gDiscType == 'percent' ? 'selected' : '' }}>%</option>
                            <option value="nominal" {{ $gDiscType == 'nominal' ? 'selected' : '' }}>Rp</option>
                        </select>
                        <input type="text" name="global_discount_value" id="globalDiscount" 
                            value="{{ number_format($gDiscVal, 0, ',', '.') }}"
                            data-raw="{{ $gDiscVal }}"
                            class="border border-gray-300 rounded px-2 py-1 w-28 text-right bg-white focus:ring-blue-500 focus:border-blue-500 input-discount">
                    </div>
                </div>

                <!-- Shipping -->
                <div class="flex justify-between items-center text-gray-600">
                    <span>Shipping</span>
                    <input type="text" name="shipping_cost" id="shipping" 
                        value="{{ number_format($shippingCost ?? 0, 0, ',', '.') }}"
                        data-raw="{{ $shippingCost ?? 0 }}"
                        class="border border-gray-300 rounded px-2 py-1 w-28 text-right bg-white focus:ring-blue-500 focus:border-blue-500 input-shipping">
                </div>

                <!-- PPN -->
                <div class="flex justify-between items-center text-gray-600">
                    <span>PPN (%)</span>
                    <input type="text" name="ppn_percent" id="ppn" 
                        value="{{ $ppnPercent ?? 0 }}"
                        data-raw="{{ $ppnPercent ?? 0 }}"
                        class="border border-gray-300 rounded px-2 py-1 w-28 text-right bg-white focus:ring-blue-500 focus:border-blue-500 input-tax">
                </div>

                <!-- Additional Fee -->
                <div class="flex justify-between items-center text-gray-600">
                    <span class="font-semibold text-blue-800">Biaya Tambahan</span>
                    <input type="text" name="additional_fee" id="additional_fee" 
                        value="{{ number_format(old('additional_fee', $additionalFee ?? 0), 0, ',', '.') }}"
                        data-raw="{{ old('additional_fee', $additionalFee ?? 0) }}"
                        class="border border-gray-300 rounded px-2 py-1 w-28 text-right bg-white focus:ring-blue-500 focus:border-blue-500 input-additional-fee">
                </div>

                <hr class="border-gray-200 my-4">

                <!-- Grand Total -->
                <div class="flex justify-between items-center font-bold text-lg text-blue-900">
                    <span>GRAND TOTAL</span>
                    <span id="grandTotal_display" class="summary-grandtotal">{{ format_rupiah($subtotal) }}</span>
                    <input type="hidden" name="grand_total" id="grand_total_input" value="{{ $subtotal }}">
                </div>

                @if($advancePaid !== null)
                    <div class="flex justify-between mt-2 text-green-600 font-semibold">
                        <span>Uang Muka</span>
                        <span>{{ format_rupiah($advancePaid) }}</span>
                    </div>
                @endif

                @if($remaining !== null)
                    <div class="flex justify-between mt-2 text-red-600 font-bold border-t border-red-100 pt-2">
                        <span>Sisa Pembayaran</span>
                        <span>{{ format_rupiah($remaining) }}</span>
                    </div>
                @endif
            </div>
        </div>