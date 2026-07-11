<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/scans.php';

if (!str_ends_with((string)$db_name, '_test'))
  throw new RuntimeException('refusing to run destructive scan storage tests outside a *_test database');

function assertScan($condition, string $message): void {
  if (!$condition)
    throw new RuntimeException($message);
}

$database = db();
$database->exec('DELETE FROM scan_port_changes');
$database->exec('DELETE FROM scans');
$database->exec('DELETE FROM scan_snapshots');

$xml = <<<'XML'
<?xml version="1.0"?>
<nmaprun scanner="nmap" version="7.98" args="nmap -A -p- 192.0.2.10" startstr="test start">
  <scaninfo type="syn" protocol="tcp" services="1-65535"/>
  <host>
    <status state="up" reason="arp-response" reason_ttl="64"/>
    <address addr="192.0.2.10" addrtype="ipv4"/>
    <address addr="00:11:22:33:44:55" addrtype="mac" vendor="Example Vendor"/>
    <hostnames><hostname name="test-host" type="PTR"/></hostnames>
    <ports>
      <extraports state="closed" count="65534"><extrareasons reason="resets" count="65534" proto="tcp" ports="1-21,23-65535"/></extraports>
      <port protocol="tcp" portid="22">
        <state state="open" reason="syn-ack" reason_ttl="64"/>
        <service name="ssh" product="OpenSSH" version="9.9" extrainfo="protocol 2.0" method="probed" conf="10"><cpe>cpe:/a:openbsd:openssh:9.9</cpe></service>
        <script id="ssh-hostkey" output="key output"><table key="rsa"><elem key="bits">4096</elem></table></script>
      </port>
    </ports>
    <os><osmatch name="Linux" accuracy="98"><osclass vendor="Linux" osfamily="Linux" osgen="6.X" type="general purpose" accuracy="98"><cpe>cpe:/o:linux:linux_kernel:6</cpe></osclass></osmatch></os>
    <uptime seconds="3600" lastboot="2026-07-11 12:00:00"/>
    <distance value="1"/>
    <hostscript><script id="uptime" output="one hour"><elem key="seconds">3600</elem></script></hostscript>
    <trace proto="tcp" port="80"><hop ttl="1" ipaddr="192.0.2.1" host="router" rtt="0.250"/></trace>
  </host>
  <runstats><finished elapsed="12.4" exit="success"/></runstats>
</nmaprun>
XML;

$scan = scanParseXml($xml, array('ip' => '192.0.2.10'));
assertScan($scan['ports'][0]['product'] === 'OpenSSH', 'service product was not parsed');
assertScan($scan['ports'][0]['scripts'][0]['nodes'][1]['value'] === '4096', 'nested script value was not parsed');
assertScan($scan['os_matches'][0]['classes'][0]['cpes'][0] === 'cpe:/o:linux:linux_kernel:6', 'OS CPE was not parsed');
assertScan($scan['trace'][0]['ip'] === '192.0.2.1', 'trace hop was not parsed');
$selfClosingNodes = scanParseScriptNodes('<script id="test"><elem key="empty"/><elem key="next">value</elem></script>');
assertScan(count($selfClosingNodes) === 2 && $selfClosingNodes[1]['parent'] === null, 'self-closing script nodes changed the hierarchy');

$firstId = scanMetadataStart('192.0.2.10', 'deep');
assertScan(scanMetadataComplete($firstId, $scan), 'first result must be marked changed');
$firstMetadata = scanMetadataById('192.0.2.10', $firstId);
$stored = scanReadSnapshot('192.0.2.10', $firstMetadata);
assertScan($stored !== null, 'stored result is unavailable');
assertScan($stored['ports'][0]['details'] === 'OpenSSH 9.9 protocol 2.0', 'stored service details changed');
assertScan($stored['scripts'][0]['id'] === 'uptime', 'host script was not stored');
assertScan((int)$database->query('SELECT COUNT(*) FROM scan_snapshot_ports')->fetchColumn() === 1, 'port row count is wrong');

$rendered = scanRenderXml($stored);
$roundTrip = scanParseXml($rendered, array('ip' => '192.0.2.10'));
assertScan(scanResultHash($roundTrip) === scanResultHash($scan), 'generated XML changed the semantic result');

$scriptOnly = $scan;
$scriptOnly['scripts'][0]['output'] = 'updated output';
$secondId = scanMetadataStart('192.0.2.10', 'deep');
assertScan(!scanMetadataComplete($secondId, $scriptOnly), 'script-only change must not be a semantic result change');
assertScan((int)$database->query('SELECT COUNT(*) FROM scan_snapshots')->fetchColumn() === 2, 'content change must create a distinct snapshot');

$serviceChange = $scriptOnly;
$serviceChange['ports'][0]['version'] = '10.0';
$serviceChange['ports'][0]['details'] = 'OpenSSH 10.0 protocol 2.0';
$thirdId = scanMetadataStart('192.0.2.10', 'deep');
assertScan(scanMetadataComplete($thirdId, $serviceChange), 'service version change was not detected');
assertScan((int)$database->query("SELECT COUNT(*) FROM scan_port_changes WHERE change_type='changed'")->fetchColumn() === 1, 'service change event was not stored');

echo "scan storage tests passed\n";
