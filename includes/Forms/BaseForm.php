<?php

namespace IMAOCustom\Forms;

use IMAOCustom\Helpers\Date;
use IMAOCustom\Services\Validation;

abstract class BaseForm {
    protected array $errors = [];
    protected array $posted = [];
    protected bool $saved    = false;
    protected string $nonce_action = '';
    protected string $nonce_name   = 'imao_nonce';

    public function __construct() {
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            $this->handle();
        }
    }

    abstract protected function fields(): array;

    abstract protected function submit(): void;

    protected function handle(): void {
        if ( ! isset( $_POST[ $this->nonce_name ] ) || ! wp_verify_nonce( $_POST[ $this->nonce_name ], $this->nonce_action ) ) {
            $this->errors[] = 'درخواست نامعتبر است.';
            return;
        }
        $this->submit();
    }

    public function error_list(): string {
        if ( empty( $this->errors ) ) {
            return '';
        }
        $out = '<ul class="notice-warning imao-errors" style="list-style: none">';
        foreach ( $this->errors as $e ) {
            $out .= '<li>' . esc_html( $e ) . '</li>';
        }
        $out .= '</ul>';
        return $out;
    }

    public function success_message(): string {
        if ( ! $this->saved ) {
            return '';
        }

        return '<div class="notice-success imao-success">اطلاعات شما با موفقیت ذخیره شد.</div>';
    }

    /**
     * Read a date from POST parts and format as Y/m/d.
     */
    protected function read_date( string $base ): string {
        $y = (int) sanitize_text_field( $_POST["{$base}_year"] ?? '' );
        $m = (int) sanitize_text_field( $_POST["{$base}_month"] ?? '' );
        $d = (int) sanitize_text_field( $_POST["{$base}_day"] ?? '' );
        if ( $y && $m && $d ) {
            return sprintf( '%04d/%02d/%02d', $y, $m, $d );
        }
        return '';
    }

    /**
     * Render three select elements for a date input.
     */
    protected function date_select( string $name, string $value = '', bool $required = false ): string {
        [ $year, $month, $day ] = array_map( 'intval', Date::split( $value ) );
        $req   = $required ? ' required' : '';
        $years = range( 1300, 1500 );
        $months = [
            1  => 'فروردین',
            2  => 'اردیبهشت',
            3  => 'خرداد',
            4  => 'تیر',
            5  => 'مرداد',
            6  => 'شهریور',
            7  => 'مهر',
            8  => 'آبان',
            9  => 'آذر',
            10 => 'دی',
            11 => 'بهمن',
            12 => 'اسفند',
        ];
        $days = range( 1, 31 );
        $out  = '<div class="date-select">';
        $out .= '<select name="' . esc_attr( $name ) . '_day"' . $req . '><option value="">روز</option>';
        foreach ( $days as $d ) {
            $out .= '<option value="' . $d . '"' . selected( $day, $d, false ) . '>' . $d . '</option>';
        }
        $out .= '</select>';
        $out .= '<select name="' . esc_attr( $name ) . '_month"' . $req . '><option value="">ماه</option>';
        foreach ( $months as $i => $label ) {
            $out .= '<option value="' . $i . '"' . selected( $month, $i, false ) . '>' . esc_html( $label ) . '</option>';
        }
        $out .= '</select>';
        $out .= '<select name="' . esc_attr( $name ) . '_year"' . $req . '><option value="">سال</option>';
        foreach ( $years as $y ) {
            $out .= '<option value="' . $y . '"' . selected( $year, $y, false ) . '>' . $y . '</option>';
        }
        $out .= '</select>';
        $out .= '</div>';
        return $out;
    }
}

