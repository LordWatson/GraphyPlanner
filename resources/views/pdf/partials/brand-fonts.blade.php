{{-- Alima's bundled Poppins Regular/SemiBold and DM Mono for dompdf (shared by every PDF view). --}}
@font-face { font-family: 'Poppins'; font-weight: normal; font-style: normal; src: url('{{ \App\Support\Pdf\PdfBrand::font('Poppins-Regular.ttf') }}') format('truetype'); }
@font-face { font-family: 'Poppins'; font-weight: bold; font-style: normal; src: url('{{ \App\Support\Pdf\PdfBrand::font('Poppins-SemiBold.ttf') }}') format('truetype'); }
@font-face { font-family: 'DM Mono'; font-weight: normal; font-style: normal; src: url('{{ \App\Support\Pdf\PdfBrand::font('DMMono-Regular.ttf') }}') format('truetype'); }
@font-face { font-family: 'DM Mono'; font-weight: bold; font-style: normal; src: url('{{ \App\Support\Pdf\PdfBrand::font('DMMono-Medium.ttf') }}') format('truetype'); }
