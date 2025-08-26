<?php

namespace IMAOCustom\Forms;

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
        $out = '<ul class="notice-warning imao-errors">';
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
}

