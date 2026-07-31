class Product {
  final String code;
  final String name;
  final String url;
  final String? price;
  final String? image;

  Product({
    required this.code,
    required this.name,
    required this.url,
    this.price,
    this.image,
  });

  factory Product.fromJson(Map<String, dynamic> j) => Product(
        code: j['code']?.toString() ?? '',
        name: j['name']?.toString() ?? '',
        url: j['url']?.toString() ?? '',
        price: j['price']?.toString(),
        image: j['image']?.toString(),
      );
}

enum MessageKind { text, trackingForm, trackingResult, escalateForm }

class ChatMessage {
  final String role; // 'user' | 'assistant'
  final String text;
  final List<Product> products;
  final MessageKind kind;
  final String? trackingNo;
  final String? carrier;

  ChatMessage({
    required this.role,
    required this.text,
    this.products = const [],
    this.kind = MessageKind.text,
    this.trackingNo,
    this.carrier,
  });
}
