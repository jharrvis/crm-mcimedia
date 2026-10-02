import './bootstrap';
import { initDueDatePickers } from './due-date-picker';

// Init dipanggil dua kali (langsung + dari DOMContentLoaded di modul picker);
// initDueDatePickers() idempoten lewat atribut data-ready.
initDueDatePickers();