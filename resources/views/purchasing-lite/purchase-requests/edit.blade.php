@extends('layouts.purchasing-lite')

@section('title', 'Edit ' . $purchaseRequest->pr_number . ' - Purchasing Lite')

@section('content')
@php
$returnStatuses = [
'revision_to_requester_from_purchasing',
'revision_from_purchasing',
];

$latestPurchasingReturnLog = null;

if (method_exists($purchaseRequest, 'logs')) {
$latestPurchasingReturnLog = $purchaseRequest->logs()
->where(function ($query) {
$query->where('action', 'send_back_to_requester')
->orWhere('action', 'returned_to_requester')
->orWhere('action', 'revision_to_requester_from_purchasing')
->orWhere('to_status', 'revision_to_requester_from_purchasing')
->orWhere('to_status', 'revision_from_purchasing');
})
->latest('acted_at')
->latest('created_at')
->first();
}

$purchasingReturnRemark =
$latestPurchasingReturnLog->remarks
?? $latestPurchasingReturnLog->remark
?? $latestPurchasingReturnLog->notes
?? $purchaseRequest->purchasing_remarks
?? $purchaseRequest->remarks
?? null;

$showPurchasingReturnRemark =
in_array((string) $purchaseRequest->status, $returnStatuses, true)
&& filled($purchasingReturnRemark);

$isReturnedPr = in_array((string) $purchaseRequest->status, $returnStatuses, true);
$canDeleteDraftPr = (string) $purchaseRequest->status === 'draft';
$currentPriority = old('priority', $purchaseRequest->priority ?? 'regular');

$isAttachmentImage = function ($path) {
return in_array(strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)), [
'jpg',
'jpeg',
'png',
'gif',
'webp',
'bmp',
'svg',
], true);
};
@endphp

<form id="edit-pr-form" method="POST" action="{{ route('purchasing-lite.purchase-requests.update', $purchaseRequest) }}" enctype="multipart/form-data" autocomplete="off">
    @csrf
    @method('PUT')

    <section class="mb-6 border border-slate-300 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-xl font-bold text-slate-950">
                    {{ $isReturnedPr ? 'Edit Returned Purchase Request' : 'Edit Draft Purchase Request' }}
                </h2>

                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <p class="text-base text-slate-600">
                        {{ $purchaseRequest->pr_number }}
                    </p>

                    <span class="{{ $purchaseRequest->priority_badge_class }}">
                        PR Priority: {{ $purchaseRequest->priority_label }}
                    </span>
                </div>
            </div>

            <a href="/purchasing-lite/dashboard" class="inline-flex h-10 items-center justify-center border border-slate-300 bg-white px-6 text-sm font-bold text-slate-800 transition hover:bg-slate-50">
                Back
            </a>
        </div>
    </section>

    @if ($errors->any())
    <section class="mb-6 border border-red-300 bg-red-50 px-5 py-4 text-sm font-medium text-red-800">
        <p class="mb-2 font-bold">Please check the form:</p>

        <ul class="list-inside list-disc space-y-1">
            @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </section>
    @endif

    @if ($showPurchasingReturnRemark)
    <section class="mb-6 border border-red-300 bg-white shadow-sm">
        <div class="border-b border-red-300 bg-red-50 px-5 py-4">
            <h3 class="text-lg font-bold text-red-800">
                Purchasing Return Remark
            </h3>
        </div>

        <div class="p-5">
            <p class="text-sm font-bold uppercase tracking-wide text-red-700">
                Please revise this PR based on the note from Purchasing
            </p>

            <div class="mt-3 border border-red-300 bg-red-50 p-4">
                <p class="whitespace-pre-line text-base font-bold text-red-900">
                    {{ $purchasingReturnRemark }}
                </p>
            </div>

            @if ($latestPurchasingReturnLog)
            <p class="mt-3 text-sm text-slate-600">
                Sent back by
                <span class="font-bold text-slate-900">
                    {{ $latestPurchasingReturnLog->user->name ?? 'Purchasing' }}
                </span>

                @if (! empty($latestPurchasingReturnLog->acted_at))
                on
                <span class="font-bold text-slate-900">
                    {{ \Carbon\Carbon::parse($latestPurchasingReturnLog->acted_at)->format('d M Y H:i') }}
                </span>
                @endif
            </p>
            @endif
        </div>
    </section>
    @endif

    <section class="border border-slate-300 bg-white shadow-sm">
        <div class="border-b border-slate-300 px-5 py-4">
            <h3 class="text-lg font-bold text-slate-950">
                PR Information
            </h3>
        </div>

        <div class="grid grid-cols-1 gap-4 p-5 md:grid-cols-4">
            <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">
                    Requester Name
                </label>

                <input type="text" name="requester_name" value="{{ old('requester_name', $purchaseRequest->requester_name) }}" autocomplete="off" spellcheck="false" class="h-11 w-full border border-slate-400 bg-white px-3 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">
                    Department
                </label>

                <input type="text" value="{{ $purchaseRequest->department_name ?? '-' }}" readonly class="h-11 w-full border border-slate-400 bg-white px-3 text-sm text-slate-900 outline-none">
            </div>

            <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">
                    Date Needed
                </label>

                <input type="date" name="date_needed" value="{{ old('date_needed', $purchaseRequest->date_needed ? $purchaseRequest->date_needed->format('Y-m-d') : '') }}" autocomplete="off" class="h-11 w-full border border-slate-400 bg-white px-3 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100">
            </div>

            <div>
                <label class="mb-2 block text-sm font-bold text-slate-700">
                    PR Priority
                </label>

                <select name="priority" class="h-11 w-full border border-slate-400 bg-white px-3 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100">
                    <option value="regular" {{ $currentPriority==='regular' ? 'selected' : '' }}>
                        Regular
                    </option>
                    <option value="important" {{ $currentPriority==='important' ? 'selected' : '' }}>
                        Important
                    </option>
                    <option value="urgent" {{ $currentPriority==='urgent' ? 'selected' : '' }}>
                        Urgent
                    </option>
                </select>
            </div>

            <div class="md:col-span-4">
                <label class="mb-2 block text-sm font-bold text-slate-700">
                    PR Title
                </label>

                <input type="text" name="title" value="{{ old('title', $purchaseRequest->title) }}" autocomplete="off" spellcheck="false" class="h-11 w-full border border-slate-400 bg-white px-3 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100">
            </div>

            <div class="md:col-span-4">
                <label class="mb-2 block text-sm font-bold text-slate-700">
                    Requester Remarks
                </label>

                <textarea name="requester_remarks" rows="3" autocomplete="off" spellcheck="false" class="w-full border border-slate-400 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100">{{ old('requester_remarks', $purchaseRequest->requester_remarks) }}</textarea>
            </div>
        </div>
    </section>

    <section class="relative z-10 mt-6 border border-slate-300 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-300 px-5 py-4">
            <div>
                <h3 class="text-lg font-bold text-slate-950">
                    Item List
                </h3>

                <p class="mt-1 text-sm text-slate-600">
                    Edit the draft items below. Existing files will be kept, and new uploaded files will be added.
                </p>
            </div>

            <button type="button" id="add-item-row" class="inline-flex h-10 items-center justify-center bg-slate-950 px-5 text-sm font-bold text-white transition hover:bg-slate-800">
                + Add Item
            </button>
        </div>

        <div class="overflow-visible">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-100">
                        <th class="w-16 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            No
                        </th>

                        <th class="min-w-[340px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Item Name
                        </th>

                        <th class="w-64 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Files
                        </th>

                        <th class="min-w-[380px] border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Specification
                        </th>

                        <th class="w-32 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Qty
                        </th>

                        <th class="w-32 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Unit
                        </th>

                        <th class="w-32 border border-slate-300 px-3 py-3 text-center font-bold text-slate-800">
                            Stock
                        </th>
                    </tr>
                </thead>

                <tbody id="item-table-body">
                    @foreach ($purchaseRequest->items as $item)
                    @php
                    $itemRowNumber = $loop->iteration;

                    $photos = $item->item_photos;

                    if (! is_array($photos) || count($photos) < 1) { $photos=$item->item_photo ? [$item->item_photo] : [];
                        }

                        $photoInputId = 'item_photos_' . $itemRowNumber;
                        @endphp

                        <tr data-item-row>
                            <td class="row-number border border-slate-300 px-3 py-2 text-center font-bold text-slate-700">
                                {{ $itemRowNumber }}
                            </td>

                            <td class="relative border border-slate-300 p-0">
                                <input type="hidden" name="items[{{ $itemRowNumber }}][id]" value="{{ $item->id }}">

                                <input type="text" name="items[{{ $itemRowNumber }}][item_name]" value="{{ old('items.' . $itemRowNumber . '.item_name', $item->item_name) }}" autocomplete="off" spellcheck="false" autocorrect="off" autocapitalize="off" class="js-item-search h-10 w-full border-0 px-3 text-sm outline-none focus:ring-2 focus:ring-blue-100">

                                <div class="js-item-results absolute left-0 right-0 top-full z-[9999] hidden border border-slate-300 bg-white shadow-lg"></div>
                            </td>

                            <td class="border border-slate-300 p-2 align-top">
                                <div class="flex items-start gap-2">
                                    <div class="js-item-photo-preview flex min-h-12 flex-1 flex-wrap gap-1">
                                        @foreach ($photos as $photo)
                                        <div class="relative h-12 w-12" data-existing-photo>
                                            <a href="{{ asset('storage/' . ltrim($photo, '/')) }}" target="_blank">
                                                @if ($isAttachmentImage($photo))
                                                <img src="{{ asset('storage/' . ltrim($photo, '/')) }}" alt="" class="h-12 w-12 border border-slate-300 object-cover">
                                                @else
                                                <span class="flex h-12 w-24 items-center border border-slate-300 bg-slate-50 px-2 text-xs font-bold text-slate-700">
                                                    {{ basename($photo) }}
                                                </span>
                                                @endif
                                            </a>

                                            <button type="button" class="js-remove-existing-photo absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center bg-red-600 text-xs font-bold leading-none text-white hover:bg-red-700" title="Remove file" data-photo-path="{{ $photo }}" data-item-row-number="{{ $itemRowNumber }}">
                                                ×
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>

                                    <label for="{{ $photoInputId }}" class="js-item-photo-label inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center bg-slate-950 text-lg font-bold leading-none text-white transition hover:bg-slate-800">
                                        +
                                    </label>

                                    <input id="{{ $photoInputId }}" type="file" name="items[{{ $itemRowNumber }}][item_photos][]" multiple class="js-item-photo-input hidden">
                                </div>
                            </td>

                            <td class="border border-slate-300 p-0">
                                <input type="text" name="items[{{ $itemRowNumber }}][specification]" value="{{ old('items.' . $itemRowNumber . '.specification', $item->specification) }}" autocomplete="off" spellcheck="false" autocorrect="off" autocapitalize="off" class="js-item-specification h-10 w-full border-0 px-3 text-sm outline-none focus:ring-2 focus:ring-blue-100">
                            </td>

                            <td class="border border-slate-300 p-0">
                                <input type="text" name="items[{{ $itemRowNumber }}][quantity]" value="{{ old('items.' . $itemRowNumber . '.quantity', rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.')) }}" min="0" step="0.01" autocomplete="off" class="h-10 w-full border-0 px-3 text-right text-sm outline-none focus:ring-2 focus:ring-blue-100">
                            </td>

                            <td class="border border-slate-300 p-0">
                                <input type="text" name="items[{{ $itemRowNumber }}][unit]" value="{{ old('items.' . $itemRowNumber . '.unit', $item->unit) }}" autocomplete="off" spellcheck="false" autocorrect="off" autocapitalize="off" class="js-item-unit h-10 w-full border-0 px-3 text-sm outline-none focus:ring-2 focus:ring-blue-100">
                            </td>

                            <td class="border border-slate-300 p-0">
                                <input type="text" name="items[{{ $itemRowNumber }}][stock]" value="{{ old('items.' . $itemRowNumber . '.stock', filled($item->stock) ? rtrim(rtrim(number_format((float) $item->stock, 2, '.', ''), '0'), '.') : '') }}" min="0" step="0.01" autocomplete="off" class="h-10 w-full border-0 px-3 text-right text-sm font-bold outline-none focus:ring-2 focus:ring-blue-100">
                            </td>
                        </tr>
                        @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-end gap-3 border-t border-slate-300 p-5">
            <button type="submit" class="inline-flex h-10 items-center justify-center border border-slate-950 bg-white px-6 text-sm font-bold text-slate-950 transition hover:bg-slate-100">
                Save Changes
            </button>
        </div>
    </section>
</form>

<section class="mt-6 flex justify-end gap-3 border border-slate-300 bg-white p-5 shadow-sm">
    @if ($canDeleteDraftPr)
    <form method="POST" action="{{ route('purchasing-lite.purchase-requests.destroy', $purchaseRequest) }}" onsubmit="return confirm('Delete this draft PR? This action cannot be undone.');">
        @csrf
        @method('DELETE')

        <button type="submit" class="inline-flex h-10 items-center justify-center border border-red-700 bg-white px-6 text-sm font-bold text-red-800 transition hover:bg-red-50">
            Delete PR
        </button>
    </form>
    @endif

    <form method="POST" action="{{ route('purchasing-lite.purchase-requests.submit', $purchaseRequest) }}" onsubmit="return confirm('Submit this PR to Purchasing? After submitting, you cannot edit it unless Purchasing returns it.');">
        @csrf

        <button type="submit" class="inline-flex h-10 items-center justify-center bg-slate-950 px-6 text-sm font-bold text-white transition hover:bg-slate-800">
            Submit to Purchasing
        </button>
    </form>
</section>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const tableBody = document.getElementById('item-table-body');
        const addButton = document.getElementById('add-item-row');
        const searchUrl = @json(route('purchasing-lite.items.search'));
        const editForm = document.getElementById('edit-pr-form');

        let searchTimers = {};
        let rowCount = tableBody.querySelectorAll('[data-item-row]').length;

        function renumberRows() {
            tableBody.querySelectorAll('[data-item-row]').forEach(function (row, index) {
                const number = index + 1;

                row.querySelector('.row-number').textContent = number;

                row.querySelectorAll('input').forEach(function (input) {
                    input.name = input.name.replace(/items\[\d+\]/, 'items[' + number + ']');
                });

                const photoInput = row.querySelector('.js-item-photo-input');
                const photoLabel = row.querySelector('.js-item-photo-label');

                if (photoInput) {
                    photoInput.id = 'item_photos_' + number;
                }

                if (photoLabel) {
                    photoLabel.setAttribute('for', 'item_photos_' + number);
                }

                row.querySelectorAll('.js-remove-existing-photo').forEach(function (button) {
                    button.setAttribute('data-item-row-number', number);
                });
            });

            rowCount = tableBody.querySelectorAll('[data-item-row]').length;
        }

        function closeAllResults() {
            document.querySelectorAll('.js-item-results').forEach(function (box) {
                box.classList.add('hidden');
                box.innerHTML = '';
            });

            document.querySelectorAll('[data-item-row] td.z-\\[9999\\]').forEach(function (cell) {
                cell.classList.remove('z-[9999]');
            });
        }

        function appendRemovedPhotoInput(photoPath, itemRowNumber) {
            if (! editForm || ! photoPath || ! itemRowNumber) {
                return;
            }

            const hiddenInput = document.createElement('input');

            hiddenInput.type = 'hidden';
            hiddenInput.name = 'items[' + itemRowNumber + '][remove_photos][]';
            hiddenInput.value = photoPath;
            hiddenInput.setAttribute('data-remove-photo-input', '1');

            editForm.appendChild(hiddenInput);
        }

        function fileIsImage(file) {
            return file && String(file.type || '').startsWith('image/');
        }

        function syncFileInputFiles(fileInput, files) {
            const dataTransfer = new DataTransfer();

            files.forEach(function (file) {
                dataTransfer.items.add(file);
            });

            fileInput.files = dataTransfer.files;
            fileInput._selectedFiles = files;
        }

        function renderPreviewFile(previewBox, fileUrl, fileName = '', isImage = false, fileInput = null, fileObject = null) {
            if (! previewBox || ! fileUrl) {
                return;
            }

            const wrapper = document.createElement('div');
            wrapper.className = isImage ? 'relative h-12 w-12' : 'relative h-12 min-w-24 max-w-40';

            if (fileObject) {
                wrapper.setAttribute('data-new-upload-preview', '1');
            }

            const link = document.createElement('a');
            link.href = fileUrl;
            link.target = '_blank';

            if (isImage) {
                const img = document.createElement('img');
                img.src = fileUrl;
                img.alt = '';
                img.className = 'h-12 w-12 border border-slate-300 object-cover';

                link.appendChild(img);
            } else {
                link.className = 'flex h-12 min-w-24 max-w-40 items-center border border-slate-300 bg-slate-50 px-2 text-xs font-bold text-slate-700 hover:bg-slate-100';
                link.textContent = fileName || 'File';
            }

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center bg-red-600 text-xs font-bold leading-none text-white hover:bg-red-700';
            removeButton.title = 'Remove file';
            removeButton.textContent = '×';

            removeButton.addEventListener('click', function () {
                if (fileInput && fileObject) {
                    const files = (fileInput._selectedFiles || []).filter(function (file) {
                        return file !== fileObject;
                    });

                    syncFileInputFiles(fileInput, files);
                }

                wrapper.remove();
            });

            wrapper.appendChild(link);
            wrapper.appendChild(removeButton);
            previewBox.appendChild(wrapper);
        }

        function setupPhotoPreview(row) {
            const fileInput = row.querySelector('.js-item-photo-input');
            const previewBox = row.querySelector('.js-item-photo-preview');

            if (! previewBox) {
                return;
            }

            row.querySelectorAll('.js-remove-existing-photo').forEach(function (button) {
                button.addEventListener('click', function () {
                    const wrapper = button.closest('[data-existing-photo]');
                    const photoPath = button.getAttribute('data-photo-path');
                    const itemRowNumber = button.getAttribute('data-item-row-number');

                    appendRemovedPhotoInput(photoPath, itemRowNumber);

                    if (wrapper) {
                        wrapper.remove();
                    }
                });
            });

            if (! fileInput) {
                return;
            }

            fileInput.addEventListener('change', function () {
                const selectedFiles = fileInput._selectedFiles || [];
                const incomingFiles = Array.from(fileInput.files || []);
                const files = selectedFiles.concat(incomingFiles);

                syncFileInputFiles(fileInput, files);

                previewBox.querySelectorAll('[data-new-upload-preview]').forEach(function (preview) {
                    preview.remove();
                });

                files.forEach(function (file) {
                    renderPreviewFile(previewBox, URL.createObjectURL(file), file.name, fileIsImage(file), fileInput, file);
                });
            });
        }

        function setupItemSearch(row) {
            const input = row.querySelector('.js-item-search');
            const resultBox = row.querySelector('.js-item-results');
            const specificationInput = row.querySelector('.js-item-specification');
            const unitInput = row.querySelector('.js-item-unit');
            const previewBox = row.querySelector('.js-item-photo-preview');
            const wrapper = input.closest('td');

            input.addEventListener('focus', function () {
                if (wrapper) {
                    wrapper.classList.add('z-[9999]');
                }
            });

            input.addEventListener('input', function () {
                const query = input.value.trim();

                clearTimeout(searchTimers[input.name]);

                if (query.length < 2) {
                    resultBox.classList.add('hidden');
                    resultBox.innerHTML = '';
                    return;
                }

                searchTimers[input.name] = setTimeout(function () {
                    fetch(searchUrl + '?q=' + encodeURIComponent(query), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                        .then(function (response) {
                            return response.json();
                        })
                        .then(function (items) {
                            resultBox.innerHTML = '';

                            if (! items.length) {
                                resultBox.innerHTML = '<div class="px-3 py-2 text-sm text-slate-500">No item found. It will be created when saved.</div>';
                                resultBox.classList.remove('hidden');

                                if (wrapper) {
                                    wrapper.classList.add('z-[9999]');
                                }

                                return;
                            }

                            items.forEach(function (item) {
                                const button = document.createElement('button');

                                button.type = 'button';
                                button.className = 'block w-full border-b border-slate-200 px-3 py-2 text-left text-sm hover:bg-slate-100';

                                button.innerHTML =
                                    '<div class="flex items-center gap-3">' +
                                        (item.image_url
                                            ? '<img src="' + escapeHtml(item.image_url) + '" class="h-10 w-10 border border-slate-300 object-cover" alt="">'
                                            : '<div class="h-10 w-10 border border-slate-300 bg-slate-100"></div>'
                                        ) +
                                        '<div>' +
                                            '<div class="font-bold text-slate-900">' + escapeHtml(item.name) + '</div>' +
                                            (item.unit ? '<div class="text-xs text-slate-500">Unit: ' + escapeHtml(item.unit) + '</div>' : '') +
                                        '</div>' +
                                    '</div>';

                                button.addEventListener('click', function () {
                                    input.value = item.name;

                                    if (item.specification) {
                                        specificationInput.value = item.specification;
                                    }

                                    if (item.unit) {
                                        unitInput.value = item.unit;
                                    }

                                    if (previewBox && item.image_url && previewBox.children.length === 0) {
                                        renderPreviewFile(previewBox, item.image_url, item.name, true);
                                    }

                                    resultBox.classList.add('hidden');
                                    resultBox.innerHTML = '';

                                    if (wrapper) {
                                        wrapper.classList.remove('z-[9999]');
                                    }
                                });

                                resultBox.appendChild(button);
                            });

                            resultBox.classList.remove('hidden');

                            if (wrapper) {
                                wrapper.classList.add('z-[9999]');
                            }
                        });
                }, 250);
            });
        }

        function createRow() {
            rowCount++;

            const row = document.createElement('tr');

            row.setAttribute('data-item-row', '');

            row.innerHTML = `
                <td class="row-number border border-slate-300 px-3 py-2 text-center font-bold text-slate-700">
                    ${rowCount}
                </td>

                <td class="relative border border-slate-300 p-0">
                    <input
                        type="hidden"
                        name="items[${rowCount}][id]"
                        value=""
                    >

                    <input
                        type="text"
                        name="items[${rowCount}][item_name]"
                        autocomplete="off"
                        spellcheck="false"
                        autocorrect="off"
                        autocapitalize="off"
                        class="js-item-search h-10 w-full border-0 px-3 text-sm outline-none focus:ring-2 focus:ring-blue-100"
                    >

                    <div class="js-item-results absolute left-0 right-0 top-full z-[9999] hidden border border-slate-300 bg-white shadow-lg"></div>
                </td>

                <td class="border border-slate-300 p-2 align-top">
                    <div class="flex items-start gap-2">
                        <div class="js-item-photo-preview flex min-h-12 flex-1 flex-wrap gap-1"></div>

                        <label for="item_photos_${rowCount}" class="js-item-photo-label inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center bg-slate-950 text-lg font-bold leading-none text-white transition hover:bg-slate-800">
                            +
                        </label>

                        <input
                            id="item_photos_${rowCount}"
                            type="file"
                            name="items[${rowCount}][item_photos][]"
                            multiple
                            class="js-item-photo-input hidden"
                        >
                    </div>
                </td>

                <td class="border border-slate-300 p-0">
                    <input
                        type="text"
                        name="items[${rowCount}][specification]"
                        autocomplete="off"
                        spellcheck="false"
                        autocorrect="off"
                        autocapitalize="off"
                        class="js-item-specification h-10 w-full border-0 px-3 text-sm outline-none focus:ring-2 focus:ring-blue-100"
                    >
                </td>

                <td class="border border-slate-300 p-0">
                    <input
                        type="text"
                        name="items[${rowCount}][quantity]"
                        min="0"
                        step="0.01"
                        autocomplete="off"
                        class="h-10 w-full border-0 px-3 text-right text-sm outline-none focus:ring-2 focus:ring-blue-100"
                    >
                </td>

                <td class="border border-slate-300 p-0">
                    <input
                        type="text"
                        name="items[${rowCount}][unit]"
                        value="pcs"
                        autocomplete="off"
                        spellcheck="false"
                        autocorrect="off"
                        autocapitalize="off"
                        class="js-item-unit h-10 w-full border-0 px-3 text-sm outline-none focus:ring-2 focus:ring-blue-100"
                    >
                </td>

                <td class="border border-slate-300 p-0">
                    <input
                        type="text"
                        name="items[${rowCount}][stock]"
                        min="0"
                        step="0.01"
                        autocomplete="off"
                        class="h-10 w-full border-0 px-3 text-right text-sm font-bold outline-none focus:ring-2 focus:ring-blue-100"
                    >
                </td>
            `;

            tableBody.appendChild(row);
            setupItemSearch(row);
            setupPhotoPreview(row);
            renumberRows();

            const firstInput = row.querySelector('.js-item-search');

            if (firstInput) {
                firstInput.focus();
            }
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        tableBody.querySelectorAll('[data-item-row]').forEach(function (row) {
            setupItemSearch(row);
            setupPhotoPreview(row);
        });

        addButton.addEventListener('click', function () {
            createRow();
        });

        document.addEventListener('click', function (event) {
            if (! event.target.closest('.js-item-search') && ! event.target.closest('.js-item-results')) {
                closeAllResults();
            }
        });
    });
</script>
@endpush
