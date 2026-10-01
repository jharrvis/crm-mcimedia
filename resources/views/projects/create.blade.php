@extends('layouts.app')

@section('title', 'Tambah Project')

@section('content')
<div class="max-w-2xl rounded-xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
    <form method="POST" action="{{ route('projects.store') }}">
        @csrf
        @include('projects._form')
    </form>
</div>
@endsection
