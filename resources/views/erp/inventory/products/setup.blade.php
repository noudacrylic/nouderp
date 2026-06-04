@if($product->type == 'ready')
    @include('erp.inventory.products.setup_ready')
@elseif($product->type == 'preorder')
    @include('erp.inventory.products.setup_preorder')
@elseif($product->type == 'bundle')
    @include('erp.inventory.products.setup_bundle')
@elseif($product->type == 'service')
    @include('erp.inventory.products.setup_service')
@elseif($product->type == 'non_stock')
    @include('erp.inventory.products.setup_non_stock')
@else
    @include('erp.inventory.products.setup_ready')
@endif