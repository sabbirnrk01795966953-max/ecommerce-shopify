@extends('layouts.admin')

@section('title','সাবক্যাটাগরি')
@section('page_title','সাবক্যাটাগরি')

@section('content')
<div class="page-actions">
    <form class="searchbar" method="get" action="{{ route('admin.subcategories.index') }}">
        <input
            type="search"
            name="q"
            value="{{ request('q') }}"
            placeholder="সাবক্যাটাগরি / ক্যাটাগরির নাম, English/Bangla, slug"
            aria-label="সাবক্যাটাগরি খুঁজুন"
            autocomplete="off"
        >
        <button class="btn" type="submit">Search</button>
        @if(request('q'))
            <a class="btn outline" href="{{ route('admin.subcategories.index') }}">Clear</a>
        @endif
    </form>

    <a class="btn primary" href="{{ route('admin.subcategories.create') }}">+ নতুন সাবক্যাটাগরি</a>
</div>

@if(request('q'))
    <div class="panel" style="padding:12px 16px;margin-bottom:14px">
        <strong>{{ $subcategories->total() }}</strong> টি result পাওয়া গেছে:
        <code>{{ request('q') }}</code>
    </div>
@endif

<div class="panel table-wrap">
    <table>
        <thead>
            <tr>
                <th>নাম</th>
                <th>ক্যাটাগরি</th>
                <th>Last URL</th>
                <th>Link</th>
                <th>স্ট্যাটাস</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
        @forelse($subcategories as $s)
            <tr>
                <td>
                    <strong>{{ $s->name_bn }}</strong>
                    @if($s->name_en && $s->name_en !== $s->name_bn)
                        <br><small>{{ $s->name_en }}</small>
                    @endif
                </td>
                <td>{{ $s->category?->name_bn }}</td>
                <td><code>{{ $s->slug }}</code></td>
                <td><a target="_blank" href="{{ route('collection.show',$s->slug) }}">Open ↗</a></td>
                <td>{{ $s->is_active?'Active':'Inactive' }}</td>
                <td class="actions">
                    <a href="{{ route('admin.subcategories.edit',$s) }}">Edit</a>
                    <form method="post" action="{{ route('admin.subcategories.destroy',$s) }}" onsubmit="return confirm('Delete?')">
                        @csrf
                        @method('DELETE')
                        <button>Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6" style="padding:30px;text-align:center">
                    কোনো matching subcategory পাওয়া যায়নি।
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

{{ $subcategories->links('vendor.pagination.default') }}
@endsection
