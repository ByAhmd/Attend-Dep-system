{{--
 | Reached when the Gate refuses a leave attachment that belongs to somebody
 | else. The exception's own message is deliberately not printed: it is
 | Laravel's English "This action is unauthorized." written for a developer,
 | and it tells the reader nothing they can act on.
--}}
@extends('errors::layout')

@section('code', '403')
@section('title', __('errors.codes.403.title'))
@section('message', __('errors.codes.403.message'))
