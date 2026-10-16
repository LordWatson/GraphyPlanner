@extends('errors.layout')

@section('code', '403')
@section('title', 'Forbidden')
@section('headline', 'Not your page to see.')
@section('message', $exception->getMessage() ?: "You don't have access to this one. If you think you should, ask your studio owner.")
