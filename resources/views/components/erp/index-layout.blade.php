<div class="bg-white rounded-lg shadow">

    <div class="flex justify-between items-center p-4 border-b">

        <div class="flex items-center gap-3">

            {{ $title }}

        </div>

        <div class="flex gap-2">

            {{ $actions }}

        </div>

    </div>


    @isset($filters)

        <div class="p-4 border-b bg-gray-50">

            {{ $filters }}

        </div>

    @endisset


    <div class="p-4 overflow-x-auto">

        {{ $slot }}

    </div>

</div>