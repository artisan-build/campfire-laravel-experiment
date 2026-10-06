@extends('layouts.app', ['title' => 'Continue sign in'])
@section('content')
<livewire:session-transfer :transfer-id="$transferId" />
@endsection
