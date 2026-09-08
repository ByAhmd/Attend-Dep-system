{{--
 | Reached when a leave request has no document any more - a link kept in a
 | message, or an attachment since discarded - and by any address that never
 | named a page.
--}}
@extends('errors::layout')

@section('code', '404')
@section('title', __('errors.codes.404.title'))
@section('message', __('errors.codes.404.message'))
