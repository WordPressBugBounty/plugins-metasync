<?php
/**
 * Robots.txt Admin Page Styles
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.6
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
?>
<style>
    /* Background provided by metasync-dashboard-wrap class */
    .metasync-dashboard-wrap .metasync-robots-txt-page {
        padding: 20px !important;
        max-width: 100% !important;
    }

    .metasync-robots-txt-page h1 {
        color: var(--dashboard-text-primary, #ffffff);
        font-size: 28px;
        font-weight: 600;
        margin: 0 0 20px 0;
        background: var(--dashboard-gradient-primary, linear-gradient(135deg, #667eea 0%, #764ba2 100%));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .metasync-robots-txt-container {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 24px;
        margin-top: 20px;
        width: 100%;
    }

    .metasync-robots-txt-editor {
        width: 100%;
        min-width: 0; /* Allow grid items to shrink */
    }

    .metasync-robots-txt-sidebar {
        width: 100%;
        min-width: 0;
    }

    @media (max-width: 1200px) {
        .metasync-robots-txt-container {
            grid-template-columns: 1fr;
        }
    }

    .metasync-card {
        background: var(--dashboard-card-bg, #1a1f26);
        border: 1px solid var(--dashboard-border, #374151);
        border-radius: 12px;
        box-shadow: var(--dashboard-shadow, 0 10px 15px -3px rgba(0, 0, 0, 0.3));
        margin-bottom: 20px;
        transition: all 0.3s ease;
    }

    .metasync-card:hover {
        box-shadow: var(--dashboard-shadow-hover, 0 20px 25px -5px rgba(0, 0, 0, 0.4));
        transform: translateY(-2px);
    }

    .metasync-card-header {
        padding: 20px 24px;
        border-bottom: 1px solid var(--dashboard-border, #374151);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: rgba(102, 126, 234, 0.05);
        border-radius: 12px 12px 0 0;
    }

    .metasync-card-header h2,
    .metasync-card-header h3 {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: var(--dashboard-text-primary, #ffffff);
    }

    .metasync-robots-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .metasync-robots-status {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: var(--dashboard-text-secondary, #9ca3af);
        padding: 6px 12px;
        background: rgba(59, 130, 246, 0.1);
        border-radius: 6px;
    }

    .metasync-editor-container {
        padding: 24px;
        width: 100%;
        box-sizing: border-box;
    }

    #robots-txt-editor {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        font-family: 'SF Mono', Monaco, 'Cascadia Code', Consolas, monospace;
        font-size: 14px;
        line-height: 1.8;
        border: 1px solid var(--dashboard-border, #374151);
        border-radius: 8px;
        padding: 16px;
        resize: vertical;
        background: rgba(0, 0, 0, 0.3);
        color: var(--dashboard-text-primary, #ffffff);
        transition: all 0.3s ease;
    }

    #robots-txt-editor:focus {
        border-color: var(--dashboard-accent, #3b82f6);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        outline: none;
        background: rgba(0, 0, 0, 0.4);
    }

    .metasync-editor-actions {
        padding: 0 24px 24px;
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }

    .metasync-editor-actions .button {
        border-radius: 8px;
        font-weight: 500;
        padding: 10px 20px;
        height: auto;
        transition: all 0.3s ease;
    }

    .metasync-editor-actions .button-primary {
        background: var(--dashboard-gradient-primary, linear-gradient(135deg, #667eea 0%, #764ba2 100%));
        border: none;
        color: #ffffff;
        box-shadow: 0 4px 6px rgba(102, 126, 234, 0.3);
    }

    .metasync-editor-actions .button-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
    }

    .metasync-editor-actions .button-secondary {
        background: var(--dashboard-card-hover, #222831);
        border: 1px solid var(--dashboard-border, #374151);
        color: var(--dashboard-text-primary, #ffffff);
    }

    .metasync-editor-actions .button-secondary:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: var(--dashboard-accent, #3b82f6);
        transform: translateY(-1px);
    }

    .metasync-validation-results {
        margin: 0 24px 24px;
        padding: 16px 20px;
        border-radius: 8px;
        border-left: 4px solid;
    }

    .metasync-validation-results.success {
        background: rgba(16, 185, 129, 0.1);
        border-left-color: var(--dashboard-success, #10b981);
        color: #6ee7b7;
    }

    .metasync-validation-results.error {
        background: rgba(239, 68, 68, 0.1);
        border-left-color: var(--dashboard-error, #ef4444);
        color: #fca5a5;
    }

    .metasync-validation-results.warning {
        background: rgba(245, 158, 11, 0.1);
        border-left-color: var(--dashboard-warning, #f59e0b);
        color: #fcd34d;
    }

    .metasync-robots-help {
        padding: 24px;
        background: rgba(102, 126, 234, 0.03);
        border-top: 1px solid var(--dashboard-border, #374151);
        border-radius: 0 0 12px 12px;
    }

    .metasync-robots-help h3 {
        margin: 0 0 16px;
        font-size: 15px;
        font-weight: 600;
        color: var(--dashboard-text-primary, #ffffff);
    }

    .metasync-help-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }

    @media (max-width: 900px) {
        .metasync-help-grid {
            grid-template-columns: 1fr;
        }
    }

    .metasync-help-item {
        font-size: 13px;
        background: rgba(0, 0, 0, 0.2);
        padding: 16px;
        border-radius: 8px;
        border: 1px solid var(--dashboard-border, #374151);
        transition: all 0.3s ease;
    }

    .metasync-help-item:hover {
        background: rgba(102, 126, 234, 0.1);
        border-color: rgba(102, 126, 234, 0.3);
        transform: translateY(-2px);
    }

    .metasync-help-item strong {
        display: block;
        margin-bottom: 8px;
        color: var(--dashboard-accent, #3b82f6);
        font-size: 14px;
    }

    .metasync-help-item p {
        margin: 0 0 10px;
        color: var(--dashboard-text-secondary, #9ca3af);
        line-height: 1.6;
    }

    .metasync-help-item code {
        display: block;
        background: rgba(0, 0, 0, 0.4);
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 12px;
        border: 1px solid var(--dashboard-border, #374151);
        color: #a78bfa;
        font-family: 'SF Mono', Monaco, Consolas, monospace;
    }

    .metasync-backups-list {
        padding: 20px;
        max-height: 450px;
        overflow-y: auto;
    }

    .metasync-card .description {
        padding: 20px;
        color: var(--dashboard-text-secondary, #9ca3af);
        text-align: center;
        margin: 0;
    }

    .metasync-backups-list::-webkit-scrollbar {
        width: 8px;
    }

    .metasync-backups-list::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 4px;
    }

    .metasync-backups-list::-webkit-scrollbar-thumb {
        background: var(--dashboard-accent, #3b82f6);
        border-radius: 4px;
    }

    .metasync-backup-item {
        padding: 16px;
        border-bottom: 1px solid var(--dashboard-border, #374151);
        background: rgba(0, 0, 0, 0.2);
        border-radius: 8px;
        margin-bottom: 12px;
        transition: all 0.3s ease;
    }

    .metasync-backup-item:hover {
        background: rgba(102, 126, 234, 0.1);
        transform: translateX(4px);
    }

    .metasync-backup-item:last-child {
        margin-bottom: 0;
    }

    .metasync-backup-info {
        margin-bottom: 12px;
    }

    .metasync-backup-info strong {
        display: block;
        font-size: 13px;
        color: var(--dashboard-text-primary, #ffffff);
        margin-bottom: 4px;
    }

    .metasync-backup-author {
        font-size: 12px;
        color: var(--dashboard-text-secondary, #9ca3af);
    }

    .metasync-backup-actions {
        display: flex;
        gap: 8px;
    }

    .metasync-backup-actions .button {
        border-radius: 6px;
        font-size: 12px;
        padding: 6px 12px;
        height: auto;
        line-height: 1.4;
    }

    .metasync-backup-actions .button-link-delete {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.3);
        color: var(--dashboard-error, #ef4444);
    }

    .metasync-backup-actions .button-link-delete:hover:not(:disabled) {
        background: rgba(239, 68, 68, 0.2);
        border-color: var(--dashboard-error, #ef4444);
        color: var(--dashboard-error, #ef4444);
        transform: translateY(-1px);
    }

    .metasync-backup-actions .button-link-delete:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .metasync-backup-actions .metasync-restore-backup {
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: var(--dashboard-success, #10b981);
    }

    .metasync-backup-actions .metasync-restore-backup:hover:not(:disabled) {
        background: rgba(16, 185, 129, 0.2);
        border-color: var(--dashboard-success, #10b981);
        color: var(--dashboard-success, #10b981);
        transform: translateY(-1px);
    }

    .metasync-backup-actions .metasync-restore-backup:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .metasync-warnings-list {
        margin: 0;
        padding: 20px;
        list-style: none;
    }

    .metasync-warnings-list li {
        display: flex;
        gap: 12px;
        margin-bottom: 16px;
        font-size: 13px;
        line-height: 1.6;
        color: var(--dashboard-text-secondary, #9ca3af);
        padding: 12px;
        background: rgba(245, 158, 11, 0.05);
        border-radius: 8px;
        border-left: 3px solid var(--dashboard-warning, #f59e0b);
    }

    .metasync-warnings-list li:last-child {
        margin-bottom: 0;
    }

    .metasync-warnings-list .dashicons {
        flex-shrink: 0;
        margin-top: 2px;
    }

    /* Modal Styles - Dark Theme */
    .metasync-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 100000;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .metasync-modal-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
    }

    .metasync-modal-content {
        position: relative;
        background: var(--dashboard-card-bg, #1a1f26);
        border: 1px solid var(--dashboard-border, #374151);
        border-radius: 16px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(102, 126, 234, 0.1);
        max-width: 600px;
        width: 90%;
        max-height: 80vh;
        display: flex;
        flex-direction: column;
        animation: modalSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-30px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .metasync-modal-header {
        padding: 24px 28px;
        border-bottom: 1px solid var(--dashboard-border, #374151);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: rgba(102, 126, 234, 0.05);
        border-radius: 16px 16px 0 0;
    }

    .metasync-modal-header h2 {
        margin: 0;
        font-size: 22px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        color: var(--dashboard-text-primary, #ffffff);
    }

    .metasync-modal-header.error {
        background: rgba(239, 68, 68, 0.1);
        border-bottom-color: rgba(239, 68, 68, 0.3);
    }

    .metasync-modal-header.error h2 {
        color: var(--dashboard-error, #ef4444);
    }

    .metasync-modal-header.warning {
        background: rgba(245, 158, 11, 0.1);
        border-bottom-color: rgba(245, 158, 11, 0.3);
    }

    .metasync-modal-header.warning h2 {
        color: var(--dashboard-warning, #f59e0b);
    }

    .metasync-modal-header.success {
        background: rgba(16, 185, 129, 0.1);
        border-bottom-color: rgba(16, 185, 129, 0.3);
    }

    .metasync-modal-header.success h2 {
        color: var(--dashboard-success, #10b981);
    }

    .metasync-modal-header.preview {
        background: rgba(59, 130, 246, 0.1);
        border-bottom-color: rgba(59, 130, 246, 0.3);
    }

    .metasync-modal-header.preview h2 {
        color: var(--dashboard-accent, #3b82f6);
    }

    .metasync-modal-close {
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--dashboard-border, #374151);
        font-size: 24px;
        line-height: 1;
        color: var(--dashboard-text-secondary, #9ca3af);
        cursor: pointer;
        padding: 0;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        transition: all 0.3s ease;
    }

    .metasync-modal-close:hover {
        background: rgba(239, 68, 68, 0.2);
        border-color: var(--dashboard-error, #ef4444);
        color: var(--dashboard-error, #ef4444);
        transform: rotate(90deg);
    }

    .metasync-modal-body {
        padding: 28px;
        overflow-y: auto;
        flex: 1;
        color: var(--dashboard-text-primary, #ffffff);
    }

    .metasync-modal-body ul {
        margin: 16px 0 0 0;
        padding-left: 28px;
        color: var(--dashboard-text-secondary, #9ca3af);
    }

    .metasync-modal-body li {
        margin-bottom: 10px;
        line-height: 1.7;
    }

    .metasync-modal-icon {
        font-size: 64px;
        text-align: center;
        margin-bottom: 20px;
        filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
        animation: iconBounce 0.5s ease-out;
    }

    @keyframes iconBounce {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
    }

    .metasync-modal-icon.error {
        color: var(--dashboard-error, #ef4444);
    }

    .metasync-modal-icon.warning {
        color: var(--dashboard-warning, #f59e0b);
    }

    .metasync-modal-message {
        font-size: 15px;
        line-height: 1.7;
        color: var(--dashboard-text-secondary, #9ca3af);
    }

    .metasync-modal-message strong {
        color: var(--dashboard-text-primary, #ffffff);
        display: block;
        margin-bottom: 12px;
        font-size: 16px;
    }

    .metasync-modal-footer {
        padding: 20px 28px;
        border-top: 1px solid var(--dashboard-border, #374151);
        display: flex;
        gap: 12px;
        justify-content: flex-start;
        background: rgba(0, 0, 0, 0.2);
        border-radius: 0 0 16px 16px;
    }

    .metasync-modal-footer-left {
        display: flex;
        gap: 12px;
    }

    .metasync-modal-footer-right {
        display: flex;
        gap: 12px;
        margin-left: auto;
    }

    .metasync-modal-footer button {
        border-radius: 6px;
        font-size: 12px;
        padding: 6px 12px;
        height: auto;
        line-height: 1.4;
        transition: all 0.3s ease;
    }

    .metasync-modal-footer-right button {
        min-width: 80px;
        padding: 6px 12px !important;
        font-size: 12px !important;
        border-radius: 6px !important;
        line-height: 1.4 !important;
        height: auto !important;
    }

    .metasync-modal-footer .button-primary {
        background: var(--dashboard-gradient-primary, linear-gradient(135deg, #667eea 0%, #764ba2 100%));
        border: none;
        color: #ffffff;
        box-shadow: 0 4px 6px rgba(102, 126, 234, 0.3);
    }

    .metasync-modal-footer .button-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 12px rgba(102, 126, 234, 0.4);
    }

    .metasync-modal-footer .button-secondary {
        background: var(--dashboard-card-hover, #222831);
        border: 1px solid var(--dashboard-border, #374151);
        color: var(--dashboard-text-primary, #ffffff);
    }

    .metasync-modal-footer .button-secondary:hover {
        background: rgba(255, 255, 255, 0.1);
        border-color: var(--dashboard-accent, #3b82f6);
        transform: translateY(-1px);
    }

    .metasync-modal-footer button.button-restore {
        background: rgba(16, 185, 129, 0.1);
        border: 1px solid rgba(16, 185, 129, 0.3);
        color: var(--dashboard-success, #10b981);
        padding: 6px 12px !important;
        font-size: 12px !important;
        border-radius: 6px !important;
        line-height: 1.4 !important;
        height: auto !important;
    }

    .metasync-modal-footer .button-restore:hover:not(:disabled) {
        background: rgba(16, 185, 129, 0.2);
        border-color: var(--dashboard-success, #10b981);
        color: var(--dashboard-success, #10b981);
        transform: translateY(-1px);
    }

    .metasync-modal-footer .button-restore:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }

    /* Preview modal specific styles */
    .metasync-preview-code {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        font-family: 'SF Mono', Monaco, 'Cascadia Code', Consolas, monospace;
        font-size: 13px;
        line-height: 1.7;
        border: 1px solid var(--dashboard-border, #374151);
        border-radius: 8px;
        padding: 16px;
        background: rgba(0, 0, 0, 0.3);
        color: var(--dashboard-text-primary, #ffffff);
        margin-top: 8px;
        overflow-x: auto;
        max-height: 500px;
        overflow-y: auto;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .metasync-preview-code code {
        font-family: inherit;
        font-size: inherit;
        color: inherit;
        background: none;
        padding: 0;
        border: none;
    }

    .metasync-preview-code::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }

    .metasync-preview-code::-webkit-scrollbar-track {
        background: rgba(0, 0, 0, 0.2);
        border-radius: 4px;
    }

    .metasync-preview-code::-webkit-scrollbar-thumb {
        background: var(--dashboard-accent, #3b82f6);
        border-radius: 4px;
    }

    /* Larger modal for preview */
    .metasync-modal-content.preview {
        max-width: 900px;
    }

    /* Preview button styling */
    .metasync-preview-backup {
        background: var(--dashboard-card-hover, #222831);
        border: 1px solid var(--dashboard-border, #374151);
        color: var(--dashboard-text-primary, #ffffff);
    }

    .metasync-preview-backup:hover {
        background: rgba(59, 130, 246, 0.2);
        border-color: var(--dashboard-accent, #3b82f6);
        color: var(--dashboard-accent, #3b82f6);
    }

    .metasync-preview-backup:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .dashicons-spin {
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
</style>
