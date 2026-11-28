{{-- Laravel's own 503 page, saying why when the app gives a reason (an agency still being set up), as its 403 page does. --}}
@extends('errors::minimal')

@section('title', __('Service Unavailable'))
@section('code', '503')
@section('message', __($exception->getMessage() ?: 'Service Unavailable'))
