<?php
/** Approved beneficiaries occupy slots; pending applications do not. */
function program_slot_summary(int $total, int $remaining): array {
    $total=max(0,$total);
    $filled=max(0,$total-$remaining);
    $remaining=max(0,$remaining);
    return ['total'=>$total,'filled'=>$filled,'remaining'=>$remaining,
        'warning'=>$total>0 && $filled/$total>=0.9,
        'label'=>number_format($filled).'/'.number_format($total).' filled · '.number_format($remaining).' left'];
}
