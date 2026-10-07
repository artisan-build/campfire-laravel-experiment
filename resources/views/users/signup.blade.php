@extends('layouts.app', ['title' => 'Sign up'])
@section('content')
<livewire:sign-up :first-run="$firstRun" :join-code="$joinCode" />
@endsection
