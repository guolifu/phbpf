<?php
$prog = <<<EOT
#include <linux/skbuff.h>
#include <uapi/linux/ip.h>

#define MAX_NB_PACKETS 1000
#define LEGAL_DIFF_TIMESTAMP_PACKETS 1000000

BPF_HASH(rcv_packets);

struct detectionPackets {
    u64 nb_ddos_packets;
};

BPF_PERF_OUTPUT(events);

int detect_ddos(struct pt_regs *ctx, void *skb){
    struct detectionPackets detectionPacket = {};

    // Used to count number of received packets
    u64 rcv_packets_nb_index = 0, rcv_packets_nb_inter=1, *rcv_packets_nb_ptr;

    // Used to measure elapsed time between 2 successive received packets
    u64 rcv_packets_ts_index = 1, rcv_packets_ts_inter=0, *rcv_packets_ts_ptr;

    /* The algorithm analyses packets received by ip_rcv function
    * and measures the difference in reception time between each packet.
    * DDOS flooders send millions of packets such that difference of
    * timestamp between 2 successive packets is so small
    * (which is not like regular applications behaviour).
    * This script looks for this difference in time and if it sees
    * more than MAX_NB_PACKETS successive packets with a difference
    * of timestamp between each one of them less than
    * LEGAL_DIFF_TIMESTAMP_PACKETS ns,
    * ------------------ It Triggers an ALERT -----------------
    * Those settings must be adapted depending on regular network traffic
    * -------------------------------------------------------------------
    * Important: this is a rudimentary intrusion detection system, one can
    * test a real case attack using hping3. However; if regular network
    * traffic increases above predefined detection settings, a false
    * positive alert will be triggered (an example would be the
    * case of large file downloads).
    */
    rcv_packets_nb_ptr = rcv_packets.lookup(&rcv_packets_nb_index);
    rcv_packets_ts_ptr = rcv_packets.lookup(&rcv_packets_ts_index);
    if(rcv_packets_nb_ptr != 0 && rcv_packets_ts_ptr != 0){
        rcv_packets_nb_inter = *rcv_packets_nb_ptr;
        rcv_packets_ts_inter = bpf_ktime_get_ns() - *rcv_packets_ts_ptr;
        if(rcv_packets_ts_inter < LEGAL_DIFF_TIMESTAMP_PACKETS){
            rcv_packets_nb_inter++;
        } else {
            rcv_packets_nb_inter = 0;
        }
        if(rcv_packets_nb_inter > MAX_NB_PACKETS){
            detectionPacket.nb_ddos_packets = rcv_packets_nb_inter;
            events.perf_submit(ctx, &detectionPacket, sizeof(detectionPacket));
        }
    }
    rcv_packets_ts_inter = bpf_ktime_get_ns();
    rcv_packets.update(&rcv_packets_nb_index, &rcv_packets_nb_inter);
    rcv_packets.update(&rcv_packets_ts_index, &rcv_packets_ts_inter);
    return 0;
}
EOT;
$b    = new Bpf(["text" => $prog]);

$b->attach_kprobe("ip_rcv", "detect_ddos");

echo "DDOS detector started ... Hit Ctrl-C to end!\n";

echo sprintf("%-26s %-10s\n", "TIME(s)", "MESSAGE");

function trigger_alert_event($ctx, $data, $size)
{
    $event = unpack("Qnb_ddos_packets", $data);
    echo sprintf("%-26s %s %ld\n", date("Y-m-d H:i:s"), "DDOS Attack => nb of packets up to now: ", $event['nb_ddos_packets']);
}

$b->events->open_perf_buffer("trigger_alert_event");

while (true) {
    try {
        $b->perf_buffer_poll();
    } catch (Exception $e) {
        exit;
    }
}
