<div class="bg-white rounded shadow">

    <table class="w-full">

        <thead class="bg-gray-100">
            <tr>

                @foreach($columns as $col)
                    <th class="p-3 text-left">
                        {{ $col }}
                    </th>
                @endforeach

                <th class="p-3 text-center">Aksi</th>

            </tr>
        </thead>

        <tbody>