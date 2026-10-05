const net = require("net");
const WebSocket = require("ws");

const TCP_PORT = 9000; // Gqrx FFT stream
const WS_PORT = 8080; // WebSocket for browser

const wss = new WebSocket.Server({ port: WS_PORT });

const tcpClient = new net.Socket();
cpClient.connect(TCP_PORT, "127.0.0.1");
tcpClient.connect(TCP_PORT, "127.0.0.1", () => {
  console.log("Connected to Gqrx FFT stream");
});

tcpClient.on("data", (data) => {
  const message = data.toString().trim();
  wss.clients.forEach((client) => {
    if (client.readyState === WebSocket.OPEN) {
      client.send(message);
    }
  });
});
